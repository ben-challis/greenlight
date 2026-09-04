.PHONY: docs docs-build docs-check docs-install

DOCS_DIR := website
DOCS_MODULES := $(DOCS_DIR)/node_modules/.package-lock.json
export ASTRO_TELEMETRY_DISABLED := 1

docs: $(DOCS_MODULES)
	npm --prefix $(DOCS_DIR) run dev

docs-install:
	npm --prefix $(DOCS_DIR) ci
	npm --prefix $(DOCS_DIR) exec -- playwright install chromium

docs-build: $(DOCS_MODULES)
	npm --prefix $(DOCS_DIR) run build

docs-check: $(DOCS_MODULES)
	npm --prefix $(DOCS_DIR) run check

$(DOCS_MODULES): $(DOCS_DIR)/package-lock.json
	npm --prefix $(DOCS_DIR) ci
