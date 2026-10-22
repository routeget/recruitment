# Tringers Public Career Portal and Career Portal Configuration
## Level 2 Functional, Technical, UI, Security and Acceptance Specification

**Document ID:** URAP-L2-CAREER-PORTAL-001  
**Version:** 1.0  
**Status:** Proposed implementation baseline  
**Date:** 2026-10-22  
**Repository:** `routeget/recruitment`  
**Target branch:** `main`  
**Audience:** Antigravity, Claude, product owner, architects, Laravel engineers, UI/UX, QA, security and operations teams  
**Parent specification:** [Unified Recruitment AI Platform Level 2 Specification v2.0](UNIFIED_RECRUITMENT_AI_PLATFORM_LEVEL_2_SPECIFICATION_v2.0.md)

> This document defines required behavior and acceptance criteria. It does not assert that any feature is already implemented. Inspect the live repository and running application before changing code. Reuse and extend existing modules where sound, and do not create a parallel career portal or duplicate configuration system.

---

## 1. Purpose and required outcome

Implement or modify the existing Tringers public career portal so its visual design, page hierarchy, content rhythm, responsive behavior and candidate journey faithfully reproduce the approved generated mockup. The mockup is the visual reference, while this document is the functional and technical source of truth.

The portal must feel like a polished, modern enterprise careers site: generous white space, clear typography, a confident blue accent, restrained rounded cards and shadows, high-quality workplace imagery, easy job discovery, featured roles, an authentic culture story and a clear path to apply. It must not be reduced to a static screenshot, a single hero section, or a hard-coded marketing page. All page sections, content, images, links, labels, job feeds, display rules and relevant visual tokens must be managed by authorized administrators.

Create a new **Career Portal Configuration** menu item under **Platform Administration**. This configuration area must allow a Platform Superadmin to manage the global public career portal and its presentation without code changes for ordinary content or layout configuration. Tenant-level branding and content must remain appropriately scoped and must not override platform-wide settings except where explicitly permitted below.

## 2. Governing requirements and domain constraints

1. **Public aggregate portal:** Requests to `https://career.tringers.com/` must display the public career portal and aggregate published, publicly visible jobs from all eligible companies/tenants. Candidates can browse and search the aggregated job listings and submit applications using the platform's established application workflow.

2. **Tenant career portals:** Tenant-specific career pages use `https://career.tringers.com/{tenant-slug}/`. The tenant slug is assigned at tenant creation, must be unique, and becomes immutable/read-only after creation. Never introduce an editable slug field or a slug-change workflow through this configuration feature. Tenant pages must show only that tenant's published jobs and tenant-authorized public content.

3. **Route and authentication separation:** Platform administration routes must remain in the platform namespace, with the platform administrator experience under `/platformadmin`. Tenant user experiences remain under `/organization`. Public career pages must not be redirected to a login page. A public candidate who selects Apply must enter the existing application flow, including sign-in or account creation only when required by that flow.

4. **Platform-owned configuration:** Platform Superadmins control the aggregate portal's global design, shared content, section order, global navigation/footer, SEO defaults, public job-search defaults, assets, and publication state. Changes must be validated, previewable, auditable, and publishable without a code deployment.

5. **Tenant isolation:** The aggregate public portal may expose only data explicitly marked public and published by the owning tenant under platform policy. A tenant portal must never reveal another tenant's jobs, branding, content, contact details, or identifiers beyond what is deliberately public in the aggregate feed. Internal requisitions, drafts, confidential roles, candidate records, hiring notes, and tenant administration data are never public.

6. **Existing application is authoritative:** Reuse current job, tenant, candidate, application, taxonomy, permissions, CMS/content, media, and audit models where possible. Do not introduce duplicate job records or a second application-submission implementation. Document any necessary schema changes and their compatibility implications.

## 3. Scope

### 3.1 Included

- Reproduce the mockup's desktop, tablet and mobile experience.
- Build or update the aggregate public portal homepage and tenant-specific portal pages.
- Add Platform Administration > Career Portal Configuration.
- Provide structured configuration for branding, theme, navigation, hero, search, popular searches, value propositions, job categories, featured jobs, culture/about content, metrics, testimonials, CTA banner, footer, SEO, social links, media assets, visibility and responsive behavior.
- Provide draft, preview, validation, publish, unpublish, rollback/version history, audit and cache invalidation behavior.
- Connect all job search, category, featured job, job detail and application interactions to existing platform routes and services.
- Add accessibility, performance, SEO, security, analytics and automated test coverage.

### 3.2 Excluded unless separately approved

- Rebuilding the recruitment workflow, candidate profile, interview, offer or hiring modules.
- Altering tenant slug immutability.
- Giving the platform administrator unrestricted access to private candidate data.
- Adding unapproved third-party tracking, advertising pixels or external scripts.
- Allowing administrators to inject arbitrary executable HTML, JavaScript or CSS into public pages.
- Publishing unpublished jobs, bypassing tenant approval, or changing job eligibility rules solely to make the homepage appear populated.

## 4. Required page structure and visual fidelity

The generated mockup is a tall desktop landing page. Implement the following sections in this order by default. The administrator may change section visibility, content, and permitted ordering through configuration. The initial published configuration must match the order below.

### 4.1 Global header and navigation

Use a clean white header with the Tringers logo at the left, primary navigation in the center, and a high-contrast blue action at the right. Keep it compact, aligned and visually light.

Default navigation labels:
- Careers
- About Us
- Life at Tringers
- Our Locations
- Students
- Diversity & Inclusion

The right-side CTA defaults to **Join Our Talent Network**. Each item must have configurable label, destination, open behavior, visibility, order, and optional audience visibility. Use internal route identifiers or validated relative URLs for internal pages. External links must be validated and opened safely. On narrow screens, collapse navigation into an accessible menu with keyboard and screen-reader support. Do not allow configuration to create duplicate IDs, unsafe destinations or broken menu structures.

The header may become sticky only if the administrator enables it and testing confirms that it does not obscure anchors, page content or mobile navigation.

### 4.2 Hero banner with image and job search

Create a wide, immersive hero with a professional workplace photograph as the background, a dark overlay for text contrast, and a left-aligned content block. On desktop, the headline and search controls occupy the left portion while the image subject remains visually prominent to the right. Crop images responsively using focal-point settings so faces are not inadvertently cut off.

Default content, editable through configuration:
- Eyebrow: **BUILD A BRIGHTER TOMORROW**
- Headline: **Grow Your Career With Tringers**
- Supporting text: **Be part of a global team solving real-world problems with technology, innovation and purpose.**
- Search input placeholder: **Search jobs by keyword, role or location**
- Primary button: **Search Jobs**
- Popular search terms: **Software Engineer**, **Product Manager**, **Data Analyst**, **Remote**, **India**

Search must be functional. Accept keyword, role/category and location according to the existing job-search API and query contract. Pressing Enter and activating Search Jobs must produce the same result. Popular-search chips must prefill or execute a search according to a configurable behavior. Do not simulate search with static results or client-only filtering of a partial dataset.

The hero must support separate desktop and mobile image assets, alternative text, image focal point, overlay strength, text alignment, minimum height, content width, button style and responsive stacking. Text must remain legible against every configured image. Provide a configured fallback if the image is missing or fails to load.

### 4.3 Value proposition strip

Immediately after the hero, render four concise value propositions in a clean horizontal row on desktop, wrapping into a balanced grid on smaller screens. Each item contains an icon or approved illustration, a heading and a short supporting paragraph.

Default content:
1. **Meaningful Work:** Solve real business challenges that make an impact.
2. **Continuous Growth:** Learn, upskill and advance your career.
3. **Inclusive Culture:** Be yourself and belong.
4. **Global Opportunities:** Work with teams across geographies.

Administrators can enable or disable each item, change text, choose from the approved icon library, change order, and set desktop column count. Do not accept arbitrary remote SVG or script payloads. Icons must be decorative when the adjacent heading already conveys the same meaning, unless the configured icon has a meaningful accessible label.

### 4.4 Explore Opportunities category cards

Render the heading **Explore Opportunities** and a right-aligned **View All Job Categories** link, followed by category cards in a consistent grid. The mockup uses six cards with soft pastel icon tiles, short category names and opening counts.

Default category examples:
- Engineering
- Product & Design
- Data & Analytics
- Consulting
- Sales & Marketing
- Corporate Functions

The category list must be derived from configured category mappings and/or existing job taxonomy. Counts must be calculated from currently published, publicly eligible jobs, not entered as permanent static numbers. Define whether counts include all eligible jobs or only jobs matching the current portal scope, and apply the same rule consistently. Do not expose confidential or draft job counts.

Each card must link to a real category-filtered job listing. Administrators can configure heading, supporting copy if enabled, displayed categories, order, icon, tile accent, visibility, and destination behavior. Prevent category links from pointing to unrelated or unauthorized pages. Empty categories should be hidden or handled using an administrator-configured empty-category policy.

### 4.5 Featured Jobs

Render a **Featured Jobs** heading and **View All Jobs** link. Use clean horizontal job rows on desktop with an icon tile, job title, category/tag, employment type, location, work arrangement, posting age and a visible navigation affordance. Rows must adapt to narrow screens without horizontal overflow.

Default visual examples from the mockup:
- Senior Software Engineer, Engineering, Full-time, Bengaluru, India, Hybrid
- Product Manager, Product, Full-time, Bengaluru, India, Hybrid
- Data Analyst, Data & Analytics, Full-time, Gurugram, India, Hybrid
- Business Consultant, Consulting, Full-time, Mumbai, India, Hybrid
- UI/UX Designer, Design, Full-time, Bengaluru, India, Hybrid

These examples are visual defaults only, not seed records or a license to invent active vacancies. Production display must be driven by real published jobs. The configuration must define selection mode, number of jobs, ordering, allowed filters, whether featured flags are respected, and the empty-state behavior. Supported selection modes should include: manually selecting eligible job IDs, using an existing featured flag, and rule-based selection such as most recent published jobs. Manual selection must revalidate eligibility at render and publish time. If a selected job closes, is unpublished, expires, or becomes ineligible, exclude it immediately and apply the configured fallback.

Each job row must link to the canonical job detail route. Apply actions must use the existing application workflow and must not bypass consent, eligibility, required questions or candidate authentication rules. Posting age and job status must be computed from authoritative data.

### 4.6 Life at Tringers culture panel

Create a two-column feature panel with a large team photograph on the left and an editorial content block on the right, using a pale blue or neutral background. Include a small eyebrow, large heading, explanatory paragraph, configurable metrics, and a primary CTA.

Default copy:
- Eyebrow: **LIFE AT TRINGERS**
- Heading: **A Place to Belong, Grow and Make an Impact**
- Body: **We empower our people with the opportunity to learn, innovate and create meaningful change for our clients, communities and the world.**
- CTA: **Explore Life at Tringers**

Mockup metric examples are **3,000+ Team Members**, **15+ Global Locations**, and **95% Employee Satisfaction**. These are illustrative and must not be published as factual claims unless the organization verifies and approves them. Every metric needs a value, label, optional description, source/verification note for internal administration, and publication toggle. Do not infer or fabricate company statistics.

Allow configuration of image, crop, layout orientation, background, eyebrow, title, rich text from a safe allowlist, metrics, CTA label/destination and section visibility. The mobile layout must place content and image in an intentional order.

### 4.7 Employee stories and testimonials

Render **Our People, Their Stories** with a **View More Stories** link and a row/grid of testimonial cards. Each card contains a quote, approved portrait or avatar, name and role. The mockup demonstrates three cards.

All testimonial content, identity, portrait, role and permission/consent status must be administratively managed. Do not publish invented testimonials, fabricated employee identities or synthetic endorsements as real employee statements. Support an explicit approved/published state and optional expiry/review date. If no approved stories exist, hide the section or use a configured non-testimonial fallback, never generate fake testimonials.

Configuration must support card count, order, quote length limit, image alt text, avatar fallback, destination link and section visibility. The public interface must not expose internal approval notes or private employee data.

### 4.8 Final CTA banner

Create a visually strong full-width banner using a suitable aspirational image, such as people helping each other on a mountain trail, with a dark overlay and left-aligned copy. Use the same restrained typography and blue/white button language as the rest of the page.

Default copy:
- Eyebrow: **YOUR NEXT OPPORTUNITY AWAITS**
- Heading: **Let’s Build What’s Next**
- Body: **Join a team of problem solvers, creators and change makers. Explore exciting career opportunities at Tringers.**
- CTA: **View All Jobs**

The CTA must link to the aggregate job listing. Administrators can configure image, focal point, overlay, text, CTA, section height, alignment and visibility. The section must remain readable and usable with images disabled or unavailable.

### 4.9 Footer

Create a clean footer with Tringers logo and short brand line on the left, grouped link columns, social icons, and a lower legal row. Default brand line: **People. Ideas. Impact.**

Default link groups:
- **Quick Links:** Careers Home, Job Categories, Life at Tringers, Our Locations
- **Useful Links:** About Us, Diversity & Inclusion, Students & Graduates, Join Our Talent Network
- **Connect With Us:** approved social destinations such as LinkedIn, X, YouTube, Instagram and Facebook
- Legal links: Privacy Policy, Terms of Use, Cookie Policy

Footer year must be dynamic unless a valid legal requirement says otherwise. Each link must be independently configurable, reorderable and validated. Social links must be omitted when no approved URL exists. Privacy, terms and cookie destinations must be real, published pages, not placeholder anchors. The cookie consent experience must follow the platform's approved consent and privacy implementation. Do not add tracking solely because a social or analytics field is configured.

## 5. Visual design system

### 5.1 Default visual direction

Use the generated mockup as the default design baseline:
- A predominantly white or near-white canvas with a confident corporate blue accent.
- Dark navy or charcoal headings, readable muted body text and clear contrast.
- A modern, highly legible sans-serif font stack with a configurable approved font choice.
- Consistent spacing, generous section separation, aligned content edges and predictable grid rhythm.
- Rounded cards and controls with subtle borders and restrained shadows.
- Small pastel icon tiles to distinguish categories without making the interface noisy.
- Large, high-quality workplace photography used selectively, with accessible alternative text and responsive crops.
- Clear hierarchy between eyebrow labels, section headings, body text, metadata and actions.
- Short, purposeful transitions only where they improve usability. Respect reduced-motion preferences.

Avoid excessive gradients, excessive animation, dense content, giant empty areas, overly rounded cartoon-style components, illegible light-gray text, inconsistent button treatments, arbitrary color usage, and visual drift from the reference.

### 5.2 Configurable theme tokens

Expose a controlled theme editor under Career Portal Configuration. At minimum support:
- Brand primary and secondary colors.
- Heading, body, muted, surface, border, success, warning and error colors, subject to contrast validation.
- Font family from a platform-approved list, font weights and base font size.
- Content max-width and standard spacing scale.
- Button variants, border radius and card shadow presets.
- Header height and sticky-header toggle.
- Hero height, overlay opacity and text placement.
- Section spacing and card/grid density.
- Desktop/tablet/mobile breakpoint presets, managed centrally and not as arbitrary executable CSS.

Theme settings must use validated design tokens, not arbitrary CSS/JavaScript. Provide a **Reset to Tringers Default Theme** action with confirmation and a preview. Resetting must affect the draft only until published.

### 5.3 Responsive behavior

The portal must be fully responsive and tested at representative widths: 360 px, 390 px, 768 px, 1024 px, 1280 px and 1440 px. The design must:
- Collapse the navigation accessibly on small screens.
- Keep hero text and job search prominent without hiding the subject or clipping text.
- Stack search fields when necessary while preserving a usable submit action.
- Reflow four value propositions and six category cards into sensible grids.
- Convert featured job rows to a readable stacked layout on mobile.
- Stack the culture panel and CTA content in an intentional order.
- Avoid horizontal scrolling, clipped buttons, overlapping controls, broken image focal points and unreadable metadata.
- Preserve comfortable touch targets and visible focus states.

## 6. Career Portal Configuration administration module

### 6.1 Navigation and access

Add **Career Portal Configuration** as a first-class menu entry under **Platform Administration**, visible to the Platform Superadmin and any separately authorized platform role explicitly granted the configuration permission. It must live within the existing platform administration navigation and route namespace. Do not place it under tenant organization settings.

Recommended route namespace:
- Platform UI: `/platformadmin/career-portal-configuration`
- Platform configuration APIs: follow the existing platform API prefix and naming conventions, with an equivalent career-portal configuration resource.
- Public root: `/` on `career.tringers.com`
- Tenant portal: `/{tenant-slug}/` on `career.tringers.com`

Before implementation, inspect existing routing and avoid collisions with current route names, job details, tenant slugs, candidate routes or platform routes. The recommended path is not permission to override an existing route without reconciliation.

### 6.2 Configuration workspace layout

Build an intuitive admin workspace with a clear page title, last-saved/published status, save draft, preview, validate, publish, unpublish where permitted, and version history actions. The configuration editor should use a left-side section navigation or tabs and a central form/preview area. A live preview should reflect draft values without publishing them publicly.

Recommended configuration groups:
1. Overview and publication
2. Brand and theme
3. Header and navigation
4. Hero and job search
5. Value propositions
6. Job categories
7. Featured jobs
8. Life at Tringers and metrics
9. Employee stories
10. Final CTA banner
11. Footer and social links
12. SEO and sharing
13. Accessibility and responsive defaults
14. Analytics and consent settings, integrated with approved platform services only
15. Preview, validation and version history

Group labels can be adjusted to fit the existing admin UI patterns, but every capability must be available and discoverable. The interface must support keyboard navigation, validation messages, unsaved-change warnings and clear confirmation for destructive or public-impacting actions.

### 6.3 Editing and publishing workflow

Implement a versioned draft-to-publish lifecycle:
- **Draft:** Editable by authorized administrators, never visible on public routes.
- **Validated draft:** Passes required structural, link, asset, accessibility and content validation. This state does not publish automatically.
- **Published:** The active public configuration used by the portal.
- **Archived version:** Retained for audit and rollback according to retention policy.

A user must explicitly publish a validated draft. Publishing must be transactional from the application's perspective: either the new complete configuration becomes active or the previous published version remains active. Do not expose a partially updated homepage while different sections are saved.

Before publish, validate required content, image asset accessibility, links, section IDs and order, theme contrast, selected job eligibility, approved testimonials, factual metrics, SEO constraints and legal page destinations. Display actionable field-level errors and a summary. A failed validation must prevent publication.

Preview modes should include aggregate public portal and tenant portal preview. Tenant preview must require selection of an existing tenant by authorized platform staff and must not change that tenant's saved slug or configuration. Clearly label previews as non-public. Use authorization checks for preview tokens and prevent indexing or cache leakage.

Publishing must create an immutable version record, record the actor and timestamp, update the active version atomically, invalidate relevant caches, and emit an auditable event. Rollback must activate a previous immutable version as a new publication event rather than rewriting historical records.

### 6.4 Configuration field model

Every configurable field must define:
- Stable machine key and human-readable label.
- Field type and validation rules.
- Default value and whether it is required.
- Scope: platform-global or tenant-overridable.
- Public/private classification.
- Editable roles and audit sensitivity.
- Preview and publication behavior.
- Localization behavior if localization is supported.
- Fallback behavior when empty, missing or invalid.

Support text, rich text from a safe allowlist, URL, color token, number, toggle, select, multi-select, ordered list, image/media reference, job selector, category selector, metric collection, testimonial collection, navigation link collection and section layout/order. Repeated collections need stable IDs so reordering does not corrupt identity or audit history.

Do not allow untrusted HTML, arbitrary JavaScript, arbitrary CSS, unvalidated URL schemes, remote scripts, iframe injection or unrestricted embed codes. Rich text must be sanitized server-side and rendered using a strict allowlist.

## 7. Scope, inheritance and tenant portal behavior

Maintain a clear distinction between platform-global and tenant-specific settings.

### 7.1 Global aggregate portal

The configuration maintained under Platform Administration controls the global root portal, shared brand, aggregate search, common sections, navigation/footer, SEO defaults and shared content. It aggregates only eligible public jobs across tenants.

### 7.2 Tenant portal

Tenant pages at `/{tenant-slug}/` must use the same coherent component system and responsive quality. Tenant-specific content and branding may override only explicitly allowed fields. The platform administrator must define which fields are global-locked and which fields are tenant-overridable. Tenant settings must not change the global root portal.

Tenant-level customization, if already supported or subsequently implemented, must be scoped to the authenticated tenant's configuration record. Do not add a tenant's editor to the platform-global settings UI without a clear scope indicator. A platform admin preview of tenant content is not equivalent to permission to silently overwrite tenant-owned content.

The tenant slug is immutable after creation. It must be displayed read-only where useful, and it must never be updated by publishing portal configuration.

### 7.3 Precedence model

Use the following precedence unless an existing documented platform policy provides a stricter rule:
1. Platform security, privacy, legal and accessibility constraints are mandatory and cannot be overridden.
2. Platform-global locked fields always win.
3. Explicitly allowed tenant overrides apply only to the tenant portal.
4. Global shared defaults fill unset tenant fields.
5. Safe built-in fallback content is used when optional values are missing.

Do not permit tenant customization to insert jobs from another tenant, modify platform routes, replace legal requirements, bypass consent, or inject executable content.

## 8. Data model and persistence

Inspect existing schemas before implementation and reuse established CMS/configuration/versioning patterns where possible. The following is a logical model, not a demand to duplicate existing tables.

Potential entities:
- `career_portal_configurations`: configuration identity, scope, tenant_id nullable for global, status, schema_version, active_published_version_id, timestamps and audit fields.
- `career_portal_configuration_versions`: immutable JSON configuration snapshot, version number, status, created_by, created_at, validation result reference, publish metadata and optional change summary.
- `career_portal_assets` or existing media library: references to managed image/media assets, alternative text, dimensions, MIME type, focal point, approval state and ownership scope.
- `career_portal_publication_events`: actor, configuration/version IDs, action, timestamp, request correlation ID and sanitized change summary.
- Existing jobs, tenants, categories, content, candidate application and audit entities should remain authoritative.

Requirements:
- Use database JSON only where it suits flexible configuration; keep frequently queried ownership, status, version, tenant scope and publication pointers in typed columns with appropriate indexes and foreign keys.
- Store media files in the existing private/public object storage model according to asset classification. Public assets must use approved public delivery mechanisms. Never put candidate CVs, private documents or private employee data in the public media namespace.
- Validate the configuration schema version when loading and publishing. Provide a controlled migration path for configuration schema changes.
- Use transactions and appropriate locking to prevent concurrent publication from silently overwriting another administrator's changes.
- Enforce uniqueness and referential integrity where appropriate.
- Never store secrets, access tokens or sensitive candidate data in configuration JSON.
- Add indexes for global active configuration lookup, tenant scope, version lookup and publication audit retrieval.
- Keep configuration history immutable and retain it according to the platform's audit/retention policy.

## 9. Application architecture and implementation boundaries

Follow the existing application stack and architectural conventions described in the parent specification. The parent baseline specifies PHP 8.5, Laravel 13, Bootstrap 5, MariaDB/InnoDB, Redis and a modular monolith approach. Verify actual repository state before implementation.

Recommended responsibilities:
- **Admin UI:** Platform Administration editor, validation feedback, live draft preview, media picker and version history.
- **Configuration service:** schema validation, authorization, draft save, versioning, publish/rollback and scope resolution.
- **Public portal rendering:** reusable, server-rendered or existing framework-compatible components for header, hero, search, value propositions, categories, jobs, culture, stories, CTA and footer.
- **Job discovery service:** existing authoritative job search and eligibility rules, with tenant scope enforced server-side.
- **Media service:** approved asset upload/selection, metadata, validation, optimization and safe public delivery.
- **Audit service:** append-only publication and sensitive configuration activity records.
- **Cache service:** cache published configuration and public queries with explicit invalidation.
- **SEO layer:** canonical URLs, metadata, structured data and sitemap integration based on published content.

Do not introduce a new frontend framework, new CMS, or external design dependency without demonstrating that the current application cannot satisfy the requirement and obtaining approval. Keep reusable portal components separate from admin-only code and candidate-private application flows.

## 10. API and route behavior

Adapt exact route naming to existing project conventions after inspection. The following contracts describe behavior, not mandatory literal URI names for APIs.

### 10.1 Platform configuration endpoints

Provide authenticated, permission-checked operations equivalent to:
- Read the current draft and published metadata.
- Create or update a draft configuration.
- Validate the draft and return structured field-level errors.
- Generate a safe preview reference for an authorized preview session.
- Publish a validated draft.
- Unpublish or revert to an approved safe default when policy allows.
- List immutable versions and publication events.
- Restore a previous version as a new draft or new publication event.
- Upload/select approved public assets through the existing media service.

All write operations require CSRF protection for session-based web requests, server-side validation, authorization, audit logging and concurrency control. Never trust a client-supplied role, tenant ID, job eligibility status, publish status or version ownership.

### 10.2 Public job search and detail

The public portal must call the existing public job discovery contract or a properly scoped service equivalent. Search supports relevant filters such as keyword, location, category, employment type, work arrangement and pagination where supported by the current product. Use allowlisted sort fields and stable pagination. Do not leak internal database errors, unpublished job fields, tenant-private details or candidate data.

Job detail pages need correct canonical URLs, open/closed status, published job content, clear Apply CTA and appropriate structured metadata. Application submission remains on the existing candidate application path.

### 10.3 Error handling

Return predictable, user-safe errors for invalid configuration, stale version conflicts, unauthorized actions, unavailable jobs, failed asset processing and unavailable search. Public pages must degrade gracefully if optional content or a secondary service is unavailable. Do not show raw stack traces or internal identifiers to public users.

## 11. SEO, social sharing and discoverability

Provide configurable global defaults and field-level overrides for:
- Page title and meta description.
- Canonical URL.
- Open Graph title, description and image.
- Social preview image.
- Robots/indexing policy.
- Organization and job-related structured data where supported by authoritative data.
- Sitemap inclusion and last-modified behavior.
- Default language and locale metadata.
- Tenant-specific page titles and canonical URLs.

The root portal canonical must point to the root domain URL, and tenant portal canonicals must use the canonical immutable tenant slug. Do not index draft previews or unpublished jobs. Do not generate JobPosting structured data for expired, draft, closed or otherwise ineligible jobs. Structured data must accurately match visible page content and authoritative job fields.

Provide a configurable robots policy subject to platform security rules. Never expose private admin or candidate routes through a public sitemap. Avoid duplicate canonical URLs across root and tenant pages.

## 12. Accessibility and inclusive candidate experience

Target WCAG 2.2 AA as the design and test goal. At minimum:
- Use semantic landmarks, headings in logical order, descriptive link text, form labels and clear error associations.
- Ensure keyboard access and visible focus indicators for all interactive elements, including the mobile menu, chips, job cards, preview controls and forms.
- Maintain sufficient text and control contrast; validate configured theme combinations.
- Provide meaningful alternative text for informative images and empty alt text for purely decorative images.
- Do not rely on color alone to communicate state.
- Respect reduced-motion preferences and do not autoplay audio/video.
- Ensure controls have adequate touch target size and predictable focus behavior.
- Make search validation and no-results states understandable to assistive technologies.
- Do not use images of text as a replacement for accessible content.
- Keep headings, buttons and job metadata legible at browser zoom and on narrow screens.

A configuration that fails required accessibility validation must not be publishable unless the finding is explicitly classified as non-blocking by approved policy. Critical contrast and keyboard failures are blocking.

## 13. Security, privacy and audit

Apply the parent specification's RBAC, ABAC, tenant isolation and audit requirements. In particular:
- Restrict global configuration edits and publishing to explicit platform permissions.
- Keep public read operations separate from authenticated admin endpoints.
- Enforce tenant scope on every tenant portal query, including cached, queued and search operations.
- Treat all editor inputs and uploaded assets as untrusted.
- Validate image type by actual file content, size and dimensions; reject executable or unsafe formats.
- Prevent stored XSS through rich text, URLs, metadata, filenames, SVG and any user-controlled display string.
- Prevent open redirects and unsafe URL schemes.
- Use rate limiting on public search and any unauthenticated endpoint as appropriate.
- Ensure preview URLs are signed, short-lived, non-enumerable and excluded from indexing and shared public caches.
- Do not cache preview content in a way that exposes draft content to other users.
- Log draft changes, validation, publish, unpublish, rollback, asset changes and permission failures with actor, timestamp, scope and correlation ID.
- Redact tokens, session IDs, secrets and candidate PII from logs.
- Do not place applicant information in analytics events or portal configuration.
- Use the approved cookie consent and privacy controls. Analytics must remain disabled until configured and legally approved where consent is required.

## 14. Performance and resilience

- Optimize hero and culture images using responsive dimensions, modern formats where supported, compression and lazy loading for below-the-fold assets. Do not lazy-load the critical hero image if that harms largest contentful paint.
- Use explicit image dimensions or aspect ratios to prevent layout shift.
- Cache only published configuration and eligible public job data. Never share tenant-scoped cache entries across tenants.
- Include tenant scope, locale and relevant search filters in cache keys.
- Invalidate the published configuration cache after successful publication or rollback. A failed publish must not evict the active working version or expose an incomplete configuration.
- Avoid N+1 queries for category counts, featured jobs, assets, stories and metrics.
- Paginate job listings and apply safe query limits.
- Use graceful fallbacks when optional sections fail, without breaking job search or job detail.
- Set practical performance budgets after measuring the current application. As initial targets, aim for Core Web Vitals in the good range at the 75th percentile, and avoid adding unbounded client-side dependencies.
- Do not allow editor-configured content or assets to trigger unbounded database queries, arbitrary remote requests or uncontrolled rendering work.

## 15. Analytics and measurable outcomes

Integrate only with existing approved analytics facilities. Support configurable, privacy-aware events such as:
- Public portal viewed.
- Search submitted, with sensitive free-text values excluded or appropriately minimized.
- Popular search selected.
- Category selected.
- Job detail viewed.
- Apply CTA selected.
- Talent network CTA selected.
- Portal configuration published, as an internal administrative audit event rather than a public marketing event.

Define event names and payloads centrally. Never send candidate names, email addresses, phone numbers, CV text, application answers, access tokens or private tenant information to general analytics. Respect consent preferences and data retention policy. The portal must remain functional when analytics are disabled or unavailable.

## 16. Testing and quality gates

Testing must cover behavior, visual fidelity, authorization, tenant isolation, accessibility, SEO, performance and publication integrity. Use the repository's existing testing framework and quality gates.

### 16.1 Functional tests

- Root URL displays the aggregate portal without authentication.
- Root job search returns only published, publicly eligible jobs from eligible tenants.
- Tenant portal shows only the selected tenant's public jobs and content.
- Search by keyword, location, category and popular-search chips works end to end.
- Category cards link to the correct filtered listing and counts match the defined eligibility rules.
- Featured jobs reflect real data and automatically exclude closed or unpublished jobs.
- Job detail and Apply links use the existing canonical workflow.
- Optional sections can be disabled and reordered without corrupting the layout.
- Draft changes do not alter the public portal until explicitly published.
- Validated publish atomically changes the active version and invalidates relevant caches.
- Invalid drafts cannot be published.
- Rollback creates a traceable event and restores the intended configuration.
- Default theme reset changes the draft only.
- Missing optional content or images uses the specified fallback behavior.
- Navigation and footer destinations are validated and functional.

### 16.2 Administration and authorization tests

- Unauthorized tenant users cannot access global configuration.
- Platform roles without the explicit permission cannot edit or publish.
- Authorized platform administrators can create, validate, preview, publish and inspect history.
- A stale concurrent draft cannot silently overwrite a newer version.
- Tenant preview does not alter tenant data or slug.
- Tenant slug remains immutable.
- CSRF, XSS, open redirect, IDOR, mass assignment and privilege escalation cases are tested.
- Preview tokens cannot be reused beyond their approved scope or duration.
- Audit records are written for all publication and rollback actions.

### 16.3 Visual regression and responsive tests

Compare the implementation against the approved generated mockup at desktop size and at tablet/mobile widths. Verify:
- Header proportions, logo alignment, hero composition, overlay, title scale and search bar.
- Section order, white space, typography, card radius, border/shadow restraint, blue accent consistency and image cropping.
- Value proposition row, category grid and featured-job row alignment.
- Culture panel image/text balance, testimonials and final CTA banner.
- Footer columns and legal row.
- Mobile navigation, card reflow, search usability and absence of horizontal overflow.

Use screenshots at consistent viewport sizes and document deviations. Visual fidelity is not achieved by using the same colors alone; the composition, hierarchy, spacing, photography and component treatment must be consistent with the mockup.

### 16.4 Accessibility, SEO and performance tests

- Automated accessibility scan plus manual keyboard and screen-reader spot checks.
- Theme contrast validation, semantic heading and image-alt checks.
- Correct title, description, canonical and social metadata for root and tenant portals.
- Structured data validation for eligible jobs only.
- Sitemap and robots behavior for public, draft, private and closed resources.
- Core Web Vitals and responsive image checks.
- No console errors, broken links, uncaught server exceptions or mixed-content resources.

### 16.5 Release gates

Do not mark the feature complete until:
1. Existing architecture and routes have been inspected and the implementation plan is documented.
2. All required sections render from configuration and real data.
3. Platform Administration configuration is functional end to end.
4. Publish, rollback, audit and cache invalidation pass tests.
5. Root and tenant isolation tests pass.
6. Critical security and accessibility defects are resolved.
7. Visual comparison demonstrates faithful reproduction at desktop and responsive sizes.
8. Automated test, static analysis, formatting and dependency security checks pass according to repository standards.
9. The documentation and requirements traceability are updated.
10. Deployment and rollback instructions are documented, and the actual deployed result is verified in the authorized environment.

## 17. Requirements traceability matrix

| ID | Requirement | Implementation area | Verification |
|---|---|---|---|
| CP-001 | Root aggregate portal at `career.tringers.com/` | Public routes and job discovery | Route and cross-tenant eligible-job tests |
| CP-002 | Tenant portal at immutable `/{tenant-slug}/` | Tenant route resolver | Slug immutability and tenant isolation tests |
| CP-003 | Match approved mockup's composition and visual system | Public portal components and theme tokens | Screenshot comparison at desktop/tablet/mobile |
| CP-004 | Functional hero search and popular searches | Search UI and existing job-search service | End-to-end search tests |
| CP-005 | Configurable value proposition section | Configuration schema and renderer | Enable, edit, reorder, disable tests |
| CP-006 | Dynamic category cards and accurate counts | Taxonomy and eligible-job query | Count and category-filter tests |
| CP-007 | Real-data featured jobs | Featured-job selector | Closed/draft/expired job exclusion tests |
| CP-008 | Culture panel and approved metrics | Content and media configuration | Validation and publication tests |
| CP-009 | Approved employee stories only | Story collection and approval state | Unapproved story exclusion tests |
| CP-010 | Configurable final CTA and footer | Configuration schema and renderer | Link validation and responsive tests |
| CP-011 | Platform Administration > Career Portal Configuration | Admin navigation, routes and permissions | Navigation and RBAC tests |
| CP-012 | Draft, validate, preview, publish, version and rollback | Configuration lifecycle service | Atomicity, version and audit tests |
| CP-013 | Platform global settings with explicit tenant overrides | Scope resolver | Precedence and isolation tests |
| CP-014 | Safe assets and sanitized rich text | Media and content services | Upload, XSS and URL validation tests |
| CP-015 | SEO and structured data correctness | Metadata and sitemap layer | Metadata and schema validation |
| CP-016 | Accessibility and responsive design | Shared components and theme validation | WCAG-oriented checks and viewport tests |
| CP-017 | Privacy-preserving analytics | Approved analytics integration | Payload review and consent tests |
| CP-018 | Performance, cache scope and invalidation | Cache/query/image layer | Cache isolation and performance tests |
| CP-019 | Immutable audit/version history | Audit and configuration version entities | Append-only/version rollback tests |
| CP-020 | Deployment and handover evidence | Release documentation | Verified release checklist |

## 18. Implementation sequence

The engineering agent must inspect the existing repository and report findings before implementation. Use the following sequence, adapting to existing architecture without weakening requirements.

**Phase 0, discovery and gap analysis**
- Inspect public routes, tenant slug resolver, job listing/detail/application flow, platform admin navigation, authorization policies, configuration/CMS patterns, media library, audit, caching, tests and deployment settings.
- Capture baseline screenshots of the current portal and identify reusable components.
- Produce a concise gap analysis and implementation map. Flag any route collisions or schema conflicts before changing them.

**Phase 1, configuration foundation**
- Define the configuration schema and scope model.
- Implement versioned persistence, authorization, validation, audit and concurrency protection.
- Add the platform admin menu entry and configuration workspace shell.
- Add schema defaults that reproduce the mockup's section structure and visual tokens.

**Phase 2, public portal design and real-data integration**
- Implement the responsive shared portal components.
- Connect search, categories, featured jobs, job detail and application links to existing services.
- Implement managed media and content rendering.
- Enforce root aggregation and tenant-specific data isolation.

**Phase 3, editor, preview and publication**
- Implement editing for all configuration groups, media selection, live preview, validation, publish, unpublish and rollback.
- Implement atomic publication and scoped cache invalidation.
- Add the approved tenant override model if required by the existing product design.

**Phase 4, quality and release readiness**
- Run functional, authorization, security, tenant isolation, accessibility, SEO, performance and visual regression tests.
- Remediate defects and repeat relevant tests.
- Verify the actual deployed portal in the authorized environment.
- Record test evidence, known non-blocking issues, rollback steps and final handover under `/FSD`.

Do not stop after producing static screens. The menu, configuration forms, preview, publish workflow, public rendering, search and job links must work end to end.

## 19. Acceptance criteria

The feature is accepted only when all of the following are true:

1. The public root visually and functionally matches the generated Tringers careers mockup, including header, hero/search, value propositions, categories, featured jobs, culture panel, approved stories, final CTA and footer.
2. The public root aggregates only published, publicly eligible jobs from all eligible tenants, and tenant pages display only their own public content.
3. **Career Portal Configuration** is visible under Platform Administration to authorized roles and is protected by server-side permissions.
4. Every requested section and core theme token can be configured without code edits, and configuration is saved persistently.
5. Draft edits never become public until a valid draft is explicitly published.
6. Preview reflects the draft, is access-controlled, and is not indexed or leaked through public caches.
7. Publish and rollback are versioned, audited, atomic and cache-safe.
8. Featured job cards and category counts reflect real eligible job data, never hard-coded mock vacancies or fabricated counts.
9. Testimonials and factual metrics require approval and are never fabricated or published merely as visual filler.
10. All navigation, search, category, job detail, Apply, talent network, social and legal links work and are validated.
11. Tenant slug immutability and all tenant data-isolation rules remain intact.
12. Responsive behavior, keyboard accessibility, contrast, SEO, performance and security pass the defined gates.
13. Automated tests, static checks and dependency/security checks pass according to repository standards.
14. The implementation, migration and rollback details, test evidence, and updated documentation are recorded under `/FSD`.

## 20. Operational guidance and rollback

Configuration publication rollback must be possible without redeploying application code. Restoring a previous published version should create a new publication event referencing the restored snapshot, preserve history, invalidate relevant caches and verify the active configuration after the change.

Database migrations must be backward-compatible where practical and include safe deployment sequencing. Avoid destructive removal of existing portal settings until equivalent data is migrated and verified. Keep the last known good published configuration available so a configuration validation or rendering defect can be reversed quickly.

Monitor portal errors, search failures, broken media, publication failures and cache invalidation failures through the existing observability stack. Alerts must not include candidate PII or secrets. If a newly published configuration causes a severe public rendering issue, authorized administrators must be able to roll back to the last known good version.

## 21. Handover deliverables

The implementation team must provide:
- A repository and route discovery summary.
- A mapping of each mockup section to its component, configuration fields and data source.
- A schema/migration summary and compatibility notes.
- The configuration permission matrix.
- Test results with coverage of the acceptance criteria.
- Desktop, tablet and mobile screenshots used for visual verification.
- Security, accessibility, SEO and performance findings.
- Deployment, configuration publish, rollback and cache invalidation instructions.
- A traceability update identifying any deviations and obtaining product-owner approval for each material deviation.

## 22. Change history

| Version | Date | Change |
|---|---|---|
| 1.0 | 2026-10-22 | Initial Level 2 specification for faithful implementation of the Tringers public career portal mockup and platform-admin configuration. |
