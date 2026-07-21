# AWS deployment

TryPost runs on one ARM EC2 instance with Docker Compose. The host contains the
app, Postgres, Redis, queues, scheduler, Reverb, and Caddy. AWS Systems Manager
replaces SSH, Secrets Manager stores the production environment, and S3 keeps
30 days of nightly database and uploaded-file backups.

Production URL: `https://trypost.superclerk.com`

## Provision

Deploy `stack.yaml` in `us-east-1` with the latest Amazon Linux 2023 ARM AMI,
the default VPC, and a public subnet. Enable CloudFormation termination
protection after creation.

Create a DNS-only Cloudflare `A` record from `trypost.superclerk.com` to the fixed
`PublicIp` stack output. Caddy obtains and renews the public TLS certificate.
Store the production dotenv file in the `trypost/production/env` secret.

## Release

The `Deploy AWS` GitHub workflow runs the existing backend and browser tests,
builds the production ARM image, tags it with the commit SHA, pushes it to ECR,
runs migrations through SSM, updates Compose, and verifies both `/up` and the
exact deployed image.

The first release may pass database and storage backup keys to
`remote-deploy.sh`. Later releases omit them; production data stays in the
named Docker volumes.

## Restore

Database backups are PostgreSQL custom-format dumps under `database/` in the
backup bucket. Uploaded files are gzip-compressed archives under `storage/`.
Restoring is explicit: stop the app, restore the selected database dump with
`pg_restore`, extract the matching storage archive into `trypost_storage`, then
run the current release normally.
