{{/*
Chart name.
*/}}
{{- define "orbit.name" -}}
{{- default .Chart.Name .Values.nameOverride | trunc 63 | trimSuffix "-" }}
{{- end }}

{{/*
Fully qualified app name.
*/}}
{{- define "orbit.fullname" -}}
{{- if .Values.fullnameOverride }}
{{- .Values.fullnameOverride | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- $name := default .Chart.Name .Values.nameOverride }}
{{- if contains $name .Release.Name }}
{{- .Release.Name | trunc 63 | trimSuffix "-" }}
{{- else }}
{{- printf "%s-%s" .Release.Name $name | trunc 63 | trimSuffix "-" }}
{{- end }}
{{- end }}
{{- end }}

{{- define "orbit.chart" -}}
{{- printf "%s-%s" .Chart.Name .Chart.Version | replace "+" "_" | trunc 63 | trimSuffix "-" }}
{{- end }}

{{/*
Common labels.
*/}}
{{- define "orbit.labels" -}}
helm.sh/chart: {{ include "orbit.chart" . }}
{{ include "orbit.selectorLabels" . }}
app.kubernetes.io/version: {{ .Chart.AppVersion | quote }}
app.kubernetes.io/managed-by: {{ .Release.Service }}
app.kubernetes.io/part-of: orbit
{{- end }}

{{- define "orbit.selectorLabels" -}}
app.kubernetes.io/name: {{ include "orbit.name" . }}
app.kubernetes.io/instance: {{ .Release.Name }}
{{- end }}

{{/*
Labels / selector labels for a component: include "orbit.componentLabels" (list . "web")
*/}}
{{- define "orbit.componentLabels" -}}
{{ include "orbit.labels" (index . 0) }}
app.kubernetes.io/component: {{ index . 1 }}
{{- end }}

{{- define "orbit.componentSelectorLabels" -}}
{{ include "orbit.selectorLabels" (index . 0) }}
app.kubernetes.io/component: {{ index . 1 }}
{{- end }}

{{- define "orbit.serviceAccountName" -}}
{{- if .Values.serviceAccount.create }}
{{- default (include "orbit.fullname" .) .Values.serviceAccount.name }}
{{- else }}
{{- default "default" .Values.serviceAccount.name }}
{{- end }}
{{- end }}

{{- define "orbit.appImage" -}}
{{- printf "%s:%s" .Values.image.app.repository (default .Chart.AppVersion .Values.image.app.tag) }}
{{- end }}

{{- define "orbit.nginxImage" -}}
{{- printf "%s:%s" .Values.image.nginx.repository (default .Chart.AppVersion .Values.image.nginx.tag) }}
{{- end }}

{{- define "orbit.secretName" -}}
{{- default (include "orbit.fullname" .) .Values.existingSecret }}
{{- end }}

{{- define "orbit.postgresql.fullname" -}}
{{- printf "%s-postgresql" (include "orbit.fullname" .) | trunc 63 | trimSuffix "-" }}
{{- end }}

{{- define "orbit.redis.fullname" -}}
{{- printf "%s-redis" (include "orbit.fullname" .) | trunc 63 | trimSuffix "-" }}
{{- end }}

{{- define "orbit.photosClaimName" -}}
{{- default (printf "%s-photos" (include "orbit.fullname" .)) .Values.persistence.existingClaim }}
{{- end }}

{{/*
Whether photos live on the PVC-backed "public" disk.
*/}}
{{- define "orbit.photosOnVolume" -}}
{{- if and (eq .Values.app.photoDisk "public") .Values.persistence.enabled }}true{{- end }}
{{- end }}

{{- define "orbit.databaseHost" -}}
{{- if .Values.postgresql.enabled }}
{{- include "orbit.postgresql.fullname" . }}
{{- else }}
{{- required "externalDatabase.host is required when postgresql.enabled=false" .Values.externalDatabase.host }}
{{- end }}
{{- end }}

{{- define "orbit.redisHost" -}}
{{- if .Values.redis.enabled }}
{{- include "orbit.redis.fullname" . }}
{{- else }}
{{- required "externalRedis.host is required when redis.enabled=false" .Values.externalRedis.host }}
{{- end }}
{{- end }}

{{- define "orbit.appUrl" -}}
{{- if .Values.app.url }}
{{- .Values.app.url }}
{{- else if and .Values.ingress.enabled .Values.ingress.hosts }}
{{- printf "%s://%s" (ternary "https" "http" (gt (len .Values.ingress.tls) 0)) (index .Values.ingress.hosts 0).host }}
{{- else }}
{{- print "http://localhost" }}
{{- end }}
{{- end }}

{{/*
Environment shared by every PHP container (web, worker, scheduler, migrations).
*/}}
{{- define "orbit.phpEnv" -}}
envFrom:
  - configMapRef:
      name: {{ include "orbit.fullname" . }}
  - secretRef:
      name: {{ include "orbit.secretName" . }}
  {{- with .Values.app.extraEnvFrom }}
  {{- toYaml . | nindent 2 }}
  {{- end }}
{{- with .Values.app.extraEnv }}
env:
  {{- toYaml . | nindent 2 }}
{{- end }}
{{- end }}

{{/*
Writable paths for PHP containers running with a read-only root filesystem.
*/}}
{{- define "orbit.phpVolumeMounts" -}}
- name: storage
  mountPath: /var/www/html/storage
- name: bootstrap-cache
  mountPath: /var/www/html/bootstrap/cache
- name: tmp
  mountPath: /tmp
{{- if include "orbit.photosOnVolume" . }}
- name: photos
  mountPath: /var/www/html/storage/app/public
{{- end }}
{{- end }}

{{- define "orbit.phpVolumes" -}}
- name: storage
  emptyDir: {}
- name: bootstrap-cache
  emptyDir: {}
- name: tmp
  emptyDir: {}
{{- if include "orbit.photosOnVolume" . }}
- name: photos
  persistentVolumeClaim:
    claimName: {{ include "orbit.photosClaimName" . }}
{{- end }}
{{- end }}

{{/*
Pod annotations that roll the pods when configuration changes.
*/}}
{{- define "orbit.configChecksums" -}}
checksum/config: {{ include (print $.Template.BasePath "/configmap.yaml") . | sha256sum }}
{{- if not .Values.existingSecret }}
checksum/secret: {{ include (print $.Template.BasePath "/secret.yaml") . | sha256sum }}
{{- end }}
{{- end }}

{{/*
Resolve a secret value: explicit value > value already stored in the release
Secret (keeps generated passwords stable across upgrades) > random.
Usage: include "orbit.secretValue" (dict "ctx" . "key" "APP_KEY" "value" .Values.app.key "generate" "appkey")
*/}}
{{- define "orbit.secretValue" -}}
{{- $existing := lookup "v1" "Secret" .ctx.Release.Namespace (include "orbit.fullname" .ctx) }}
{{- if .value }}
{{- .value }}
{{- else if and $existing $existing.data (hasKey $existing.data .key) }}
{{- index $existing.data .key | b64dec }}
{{- else if eq .generate "appkey" }}
{{- printf "base64:%s" (randAscii 32 | b64enc) }}
{{- else if eq .generate "password" }}
{{- randAlphaNum 24 }}
{{- end }}
{{- end }}
