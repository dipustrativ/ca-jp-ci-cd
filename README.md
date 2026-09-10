# CarpetAvenue

Magento 2.4.9 Community Edition storefront for CarpetAvenue, an online carpet and
rug retailer. Dev codename `carpient`. This directory is the Magento application
root; the parent directory holds the local Docker stack.

## Where to look

| | |
|---|---|
| **[docs/README.md](docs/README.md)** | **The knowledge base — start here.** Task index, chapters, and the rules that keep them organised |
| [CLAUDE.md](CLAUDE.md) | Working locally: containers, CLI, static content, tests, module ecosystem |
| [docker/README.md](docker/README.md) | Production image, build platforms, deploy paths, rollback |
| [docker/INFRA-CI.md](docker/INFRA-CI.md) | Shared MySQL/OpenSearch/RabbitMQ, CI/CD, server ops, open items |
| [docker/TROUBLESHOOTING.md](docker/TROUBLESHOOTING.md) | Production symptom → cause → fix |

Common tasks:

- [Build and deploy](docs/runbooks/build-and-deploy.md)
- [Add a website on its own domain](docs/runbooks/add-website.md)
- [Add a store view](docs/runbooks/add-store-view.md)
- [Known upstream issues](docs/Issues/README.md) — check before debugging anything inside `vendor/`
