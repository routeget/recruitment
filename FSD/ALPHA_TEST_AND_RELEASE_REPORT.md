# Unified Recruitment AI Platform — Alpha Test and Release Report

**Document ID:** URAP-ALPHA-REPORT-001  
**Version:** 1.0  
**Status:** Active — Appending Increment Evidence  
**Date:** 2026-10-22  
**Owner / Audience:** QA Lead, Security Engineer, Release Owner, Product Owner  
**Baseline Dependency:** [Level 2 Specification v2.0](UNIFIED_RECRUITMENT_AI_PLATFORM_LEVEL_2_SPECIFICATION_v2.0.md) (`URAP-L2-SPEC-001`)

---

## Increment 0 — Discovery, Server Baseline & Governance Setup

- **Timestamp:** 2026-10-22T13:58:00+05:30
- **Target Environment:** `https://career.tringers.com` (`/home/career/htdocs/career.tringers.com`)
- **Base Commit SHA:** `497226c` (`main`)
- **Runtime Verification (No Secrets Printed):**
  - OS: Ubuntu 24.04.5 LTS (`x86_64`)
  - PHP: `8.5.11` (`cli` & `fpm-fcgi`)
  - Composer: `2.10.2`
  - Node.js / npm: `v24.22.0` / `11.19.1`
  - MariaDB: `12.3.3-MariaDB-ubu2404` (`DB_CONNECTED=yes`, `0` initial tables)
  - Redis: Connected (`REDIS_CONNECTED=yes`, `PONG`)
  - `.env` Safety: Preserved in place and backed up with permissions (`0770 career:career`) to `/home/career/backups/.env.backup.initial`.
- **Live HTTPS Smoke Check:**
  - `https://career.tringers.com` responds `HTTP/2 200` over TLS 1.3 via NGINX.
- **Deliverables Created:**
  - `FSD/ALPHA_IMPLEMENTATION_PLAN.md`
  - `FSD/ALPHA_REQUIREMENTS_TRACEABILITY.md`
  - `FSD/ALPHA_DEPLOYMENT_RUNBOOK.md`
  - `FSD/ALPHA_TEST_AND_RELEASE_REPORT.md`
