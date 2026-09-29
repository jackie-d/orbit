# Orbit — common development tasks.   Run `make help` for the list.

SHELL := /bin/bash
.DEFAULT_GOAL := help

APP_IMAGE    ?= orbit-app
NGINX_IMAGE  ?= orbit-nginx
TAG          ?= dev
KIND_CLUSTER ?= orbit
NAMESPACE    ?= orbit
RELEASE      ?= orbit
CHART        := helm/orbit

.PHONY: help
help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## ' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-20s\033[0m %s\n", $$1, $$2}'

# --- Local PHP --------------------------------------------------------------

.PHONY: install
install: ## Install Composer dependencies
	composer install

.PHONY: test
test: ## Run the test suite (sqlite in memory)
	php artisan test

.PHONY: lint
lint: ## Check code style (Laravel Pint)
	vendor/bin/pint --test

.PHONY: format
format: ## Fix code style
	vendor/bin/pint

# --- Docker -----------------------------------------------------------------

.PHONY: env
env: ## Create .env for docker compose with a fresh APP_KEY (if missing)
	@test -f .env || cp .env.example .env
	@grep -q '^APP_KEY=base64:' .env || { \
		key="base64:$$(openssl rand -base64 32)"; \
		sed -i.bak "s#^APP_KEY=.*#APP_KEY=$$key#" .env && rm -f .env.bak; \
		echo "APP_KEY generated in .env"; }

.PHONY: build
build: ## Build the app and nginx images
	docker build --target app   -t $(APP_IMAGE):$(TAG)   .
	docker build --target nginx -t $(NGINX_IMAGE):$(TAG) .

.PHONY: up
up: env ## Start the stack with docker compose (http://localhost:8080)
	ORBIT_APP_IMAGE=$(APP_IMAGE):$(TAG) ORBIT_NGINX_IMAGE=$(NGINX_IMAGE):$(TAG) docker compose up -d --build
	@echo "Orbit is starting on http://localhost:8080 — try: make smoke"

.PHONY: down
down: ## Stop the stack (keeps volumes)
	docker compose down

.PHONY: logs
logs: ## Follow the stack logs
	docker compose logs -f

.PHONY: smoke
smoke: ## End-to-end smoke test against BASE_URL (default http://localhost:8080)
	scripts/smoke-test.sh

# --- Helm / Kubernetes ------------------------------------------------------

.PHONY: helm-lint
helm-lint: ## Lint the chart with every values file
	helm lint --strict $(CHART) -f $(CHART)/values-dev.yaml
	helm lint --strict $(CHART) -f $(CHART)/values-prod.yaml

.PHONY: helm-template
helm-template: ## Render the chart with dev values
	helm template $(RELEASE) $(CHART) -n $(NAMESPACE) -f $(CHART)/values-dev.yaml

.PHONY: kind-up
kind-up: build ## Create a kind cluster, load the images and install the chart
	kind get clusters | grep -qx $(KIND_CLUSTER) || kind create cluster --name $(KIND_CLUSTER)
	kind load docker-image --name $(KIND_CLUSTER) $(APP_IMAGE):$(TAG) $(NGINX_IMAGE):$(TAG)
	helm upgrade --install $(RELEASE) $(CHART) -n $(NAMESPACE) --create-namespace \
		-f $(CHART)/values-dev.yaml \
		--set image.app.repository=$(APP_IMAGE) --set image.app.tag=$(TAG) \
		--set image.nginx.repository=$(NGINX_IMAGE) --set image.nginx.tag=$(TAG) \
		--wait --timeout 10m
	helm test $(RELEASE) -n $(NAMESPACE)
	@echo "Run 'make kind-port-forward' then 'make smoke'"

.PHONY: kind-port-forward
kind-port-forward: ## Forward http://localhost:8080 to the Orbit service
	kubectl -n $(NAMESPACE) port-forward svc/$(RELEASE) 8080:80

.PHONY: kind-down
kind-down: ## Delete the kind cluster
	kind delete cluster --name $(KIND_CLUSTER)
