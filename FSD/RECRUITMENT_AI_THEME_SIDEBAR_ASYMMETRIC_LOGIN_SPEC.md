# Recruitment AI — Theme, Sidebar and Asymmetric Login Design Replication Specification

**Document type:** Authoritative UI/UX implementation specification  
**Target repository:** routeget/recruitment  
**Reference repositories reviewed:** routeget/Rupyk-Commerce, routeget/QuickBookERP  
**Scope:** Application theme, authenticated shell/top bar, sidebar/navigation panel, responsive behaviour, and asymmetric login page  
**Status:** Specification for Antigravity implementation; this document alone does not change application runtime.

---

## 1. Objective

Bring Recruitment AI's visual system closer to the mature enterprise interface patterns already used in the Routeget QuickBook ERP and Rupyk Commerce applications. The desired result is a cohesive, polished enterprise application with:

1. A consistent design-token system and typography.
2. An enterprise top application bar.
3. A refined, collapsible, scrollable left navigation panel with clear section groupings and active-route state.
4. An asymmetric, split-screen login experience with a configurable brand/visual panel and a focused authentication form.
5. Responsive behaviour for desktop, tablet, and mobile.
6. Accessibility, security, and functional correctness preserved.

This is a replication of design patterns and interaction behaviour, not a blind copy of the source applications' business labels, product branding, menus, or domain-specific functionality. Recruitment AI must retain its own identity, role-specific navigation, routes, authorization, and workflows.

## 2. Source repositories and inspected files

### 2.1 Rupyk Commerce — primary reference for the shell and asymmetric login

- Application layout: https://github.com/routeget/Rupyk-Commerce/blob/main/resources/views/layouts/app.blade.php
- Login view: https://github.com/routeget/Rupyk-Commerce/blob/main/resources/views/auth/login.blade.php

Observed patterns in the inspected source:

- Microsoft Dynamics 365 / Fluent-inspired design tokens.
- Configurable primary brand colour and top-bar background.
- A dark navy application top bar, approximately 48 px high.
- A white navigation drawer/sidebar approximately 260 px wide.
- Sticky top bar and sticky sidebar with its own scroll region.
- A collapsible sidebar, grouped navigation sections, and hover/active treatments.
- A top-bar search control, navigation icon controls, and application-level chrome.
- An asymmetric authentication layout at desktop widths of at least 1024 px: approximately 75% brand/image panel and 25% form panel.
- The brand panel is hidden on narrower screens in the source design, while the form panel occupies the full width.
- A configurable background image, dark gradient overlay, brand heading/subheading, and a separately scrollable form panel.
- Dynamic brand colour, metadata, and favicon settings.

These are observed reference characteristics, not a requirement to copy Commerce-specific routes or text.

### 2.2 QuickBook ERP — secondary reference for sidebar detail and login composition

- Application layout: https://github.com/routeget/QuickBookERP/blob/main/resources/views/layouts/app.blade.php
- Login view: https://github.com/routeget/QuickBookERP/blob/main/resources/views/auth/login.blade.php
- Dynamic branding/theme partial: https://github.com/routeget/QuickBookERP/blob/main/resources/views/layouts/partials/branding-styles.blade.php

Observed patterns in the inspected source:

- Enterprise typography using locally served Inter font assets with a Bootstrap 5.3 layout and Bootstrap Icons.
- A white 260 px sidebar with a subtle divider.
- Compact, carefully spaced navigation rows with icon/text alignment.
- Section headers using uppercase labels, subdued secondary colour, and letter spacing.
- Active navigation highlighted with a pale blue selected surface, primary blue text, stronger weight, and a slim primary-colour left indicator.
- Hover states that are clear but not visually noisy.
- Collapsible navigation groups and indented sub-navigation.
- Search designed for the application shell, including keyboard hint and results styling.
- A theme token layer that maps the configured primary colour, header colours, surfaces, borders, hover/selected states, typography, and Bootstrap variables.
- An asymmetric login layout using a dark blue gradient brand panel, a subtle SVG wave decoration, and a white authentication form panel. The source uses a 7/5 Bootstrap column split, approximately 58/42, which differs from Rupyk Commerce's 75/25 layout.

Use QuickBook's sidebar details and theme-token discipline. For Recruitment AI's login composition, use the more pronounced 75/25 asymmetry as the primary reference, with restrained wave/abstract decoration inspired by QuickBook where it improves the result.

### 2.3 Current Recruitment AI baseline

- Application layout: https://github.com/routeget/recruitment/blob/main/resources/views/layouts/app.blade.php
- Login view: https://github.com/routeget/recruitment/blob/main/resources/views/auth/login.blade.php

The currently inspected Recruitment AI layout uses a dark navy sidebar, a dark top navbar, Bootstrap 5.3, and a centred login card with a separate demo-persona panel. The target is to evolve this existing implementation—not replace its authorization-aware navigation or authentication flow with static markup.

**Important:** inspect the latest branch before implementation. The source files may have changed since this analysis.

---

## 3. Design direction

Adopt a modern enterprise design inspired by Microsoft Dynamics 365 / Fluent UI:

- **Top bar:** deep navy, full width, compact, high-contrast.
- **Sidebar:** white or very light neutral surface, thin neutral divider, clear grouped navigation.
- **Primary action:** configurable brand blue, defaulting to a restrained enterprise blue such as #0078D4 if no approved platform theme value exists.
- **Header/nav background:** configurable deep navy, defaulting to #002050 or #004578 based on the approved theme mapping.
- **Canvas:** light neutral grey such as #F3F2F1 / #F5F5F5.
- **Surface:** white.
- **Text:** high-contrast charcoal; secondary labels muted grey.
- **Selected navigation:** pale blue surface such as #DEECF9 / #EFF6FC, primary blue text, and a slim left-edge accent.
- **Borders:** subtle neutral grey.
- **Typography:** prefer locally available Inter if already included; otherwise use a system stack beginning with Segoe UI. Do not add external font dependencies without checking current project policy and network requirements.
- **Icons:** use the icon set already installed in Recruitment AI. Do not add duplicate icon libraries unnecessarily.
- **Density:** compact enterprise navigation and readable content; avoid oversized consumer-app spacing.
- **Motion:** subtle, short transitions; respect reduced-motion preferences.

Colours are design defaults, not hard-coded values scattered throughout templates. Resolve them through a central token layer and the existing platform branding/configuration system where practical.

## 4. Central design tokens

Implement a single, documented CSS token layer, preferably in a dedicated asset such as resources/css/platform-theme.css or the repository's established stylesheet location. Do not create a second competing design system if an existing asset or branding service already fulfils this purpose.

At minimum define semantic tokens for:

- Primary action, primary hover, primary active.
- Header/top-bar background and header hover.
- Canvas, primary surface, secondary surface, elevated surface.
- Primary text, secondary text, disabled text.
- Input border, divider border, focus ring.
- Hover surface, selected surface.
- Success, warning, danger, and informational states.
- Sidebar width, top-bar height, navigation row height, and standard radius.
- Body, heading, and form-label typography.

Use semantic names, for example --app-primary, --app-header-bg, --app-canvas, --app-surface, --app-text, --app-text-muted, --app-border, --app-hover, and --app-selected.

The default palette should be aligned to the reference pattern, but settings must be validated before being emitted into CSS. Do not allow arbitrary unvalidated values or CSS injection. If platform branding is configurable, provide safe fallbacks and ensure text contrast remains accessible.

## 5. Authenticated application shell and top bar

Implement the following visual structure while preserving existing routes and role/permission checks:

1. Full-width top application bar.
2. Brand/application mark and product name.
3. Navigation/sidebar toggle.
4. Global or navigation search only if it can be connected to real, authorized destinations.
5. Optional breadcrumb/page context where supported by the current application.
6. Right-aligned user identity and tenant/context indicators where relevant.
7. Sign-out action using the existing CSRF-protected POST route.
8. Main content region with appropriate responsive padding and a stable width.

Reference measurements:

- Top bar: approximately 48 px desktop height, adjustable if existing accessibility or content requirements need more room.
- Sidebar: approximately 260 px expanded width.
- Sidebar content: independently scrollable; avoid scrolling the entire page just to reach lower navigation items.
- Main content: flexible width, no horizontal overflow at common viewport sizes.

Keep the current Recruitment AI product name and brand identity. Do not introduce QuickBook or Rupyk Commerce branding into the target application.

## 6. Sidebar/navigation panel specification

### 6.1 Appearance

- White/light-neutral background with a thin right divider.
- Expanded desktop width around 260 px.
- Compact navigation items with aligned icons and labels.
- Group headings in uppercase or small caps, muted colour, modest letter spacing.
- Consistent vertical rhythm and readable hit targets.
- Active item: pale blue selected surface, primary blue text, semibold/bold weight, and a 3 px or similarly slim left indicator.
- Hover item: subtle light-blue/neutral surface and clear focus treatment.
- Nested items: indentation and slightly smaller spacing without losing readability.
- Optional count/status badges only where real data supports them.
- Collapsible groups with accessible controls, clear expanded state, and a subtle chevron.
- A compact/collapsed sidebar mode only if all navigation remains discoverable and usable.

### 6.2 Navigation content and authorization

- Preserve the existing route names, permission checks, active-route matching, and tenant isolation.
- Preserve the platform-level administration link only for users who pass the existing server-authorized platform Super Admin condition.
- Build navigation from the current application's real modules. Do not copy QuickBook's finance/accounting menu or Rupyk's commerce menu.
- Do not reveal privileged route names or actionable controls to unauthorized users when the current design intentionally hides them.
- Hiding a link is not authorization: every route must retain its server-side middleware/policy checks.
- No placeholder links, dead anchors, href="#" menu items, or buttons without working actions.
- Active states must be correct for index, show, edit, and nested routes.
- Preserve accessibility labels and the skip-to-main-content link.

### 6.3 Sidebar behaviour

- Desktop: sticky below the top bar; scroll only the navigation region.
- Mobile/tablet: use an accessible off-canvas drawer or collapsible panel. Provide a visible toggle, close control, escape-key handling where applicable, focus management, and a sensible backdrop.
- Keep the selected page visible in the navigation if practical, including expansion of its parent group.
- Ensure opening/closing the sidebar does not cause horizontal overflow or trap keyboard focus.
- Respect reduced-motion settings.

## 7. Asymmetric login page

### 7.1 Desktop composition

Use the Rupyk Commerce asymmetric split-screen composition as the main reference:

- At viewport widths of at least 1024 px, the left brand panel should occupy approximately 75% of the viewport width and the right authentication panel approximately 25%.
- The brand panel fills the available viewport height and uses a configurable background image or designed abstract visual, with a dark overlay that maintains text contrast.
- Place product branding and a concise Recruitment AI value proposition in a controlled overlay layout.
- Use restrained abstract curves/waves or geometric forms inspired by QuickBook's SVG-wave treatment only if they complement the selected image and do not distract.
- The right panel uses a clean white surface, with a constrained and readable authentication form.
- Keep logo, heading, labels, input controls, validation errors, password controls if already supported, SSO action if configured, submit button, and required legal/footer links aligned consistently.
- Use the real platform branding values where available. Never use QuickBook or Rupyk logos, product text, or financial/commerce copy.

The 75/25 ratio is a target, not a reason to make the login form unusably narrow. If form content or localization requires more width, use a measured responsive range (for example, 68/32 or a minimum form width) while preserving the asymmetric visual hierarchy.

### 7.2 Mobile and tablet

- On narrow viewports, use a single-column login experience.
- Hide or reduce the large brand panel if it would impair authentication; the form must occupy the available width.
- Retain a compact logo/brand header and optional small accent visual.
- Ensure the form is vertically scrollable; never lock the entire page to overflow:hidden if that prevents access to fields, validation messages, or the submit button.
- Avoid clipping on short screens, landscape orientation, browser zoom, and mobile virtual keyboards.
- Maintain touch-friendly controls and visible keyboard focus.

### 7.3 Authentication and demo persona safety

Preserve the existing login route, CSRF token, rate limits, session handling, error behaviour, password autocomplete semantics, and Microsoft Entra SSO fail-closed behaviour.

The current Recruitment AI login view includes a development demo-persona panel. Inspect its controller, environment gating, and seeder behaviour. It must only render in the explicitly approved development/testing context. It must not expose synthetic credentials, quick-fill controls, or demo-persona details in a production environment. Do not weaken security to reproduce the reference layout.

If the temporary development/testing exception is currently hosted on the publicly reachable career.tringers.com, keep this risk explicitly documented and do not broaden demo credential exposure. The redesign must not display passwords in explanatory text or provide hard-coded password quick-fill logic outside the approved development-only guard.

### 7.4 Brand panel content and configuration

Use the platform's actual configurable identity where available:

- Display name / brand code.
- Logo and favicon.
- Approved background image or abstract visual.
- Main headline and supporting statement.
- Optional legal/footer text.

If these settings do not exist, use safe application defaults and document the gap. Do not create a generic arbitrary-settings editor. Any new configurable fields must use validation, appropriate storage, authorization, and audit policy.

Do not depend on a remote image host for essential login branding if a local asset can be safely shipped. Ensure the page remains attractive when no image/logo is configured.

## 8. Implementation boundaries

1. First inspect the current main branch, the current login controller/view data contract, layout, CSS/JS assets, platform branding configuration, navigation routes, and all relevant tests.
2. Create a dedicated feature branch from the latest main.
3. Implement the specification in the Recruitment AI repository only. The reference repositories are read-only design sources for this task; do not modify them.
4. Reuse the current Bootstrap version and dependencies unless a concrete, justified incompatibility requires change.
5. Keep theme tokens centralized. Avoid duplicating large inline style blocks across layouts.
6. Keep authentication form behaviour and server-side authorization unchanged except for explicitly justified fixes.
7. Do not change business workflows, route names, role semantics, tenant policies, database records, or integration safety gates as part of a visual redesign.
8. Avoid adding a new frontend framework unless the existing architecture cannot meet the requirement and the trade-off is justified.
9. Ensure any custom JavaScript is progressive, accessible, and does not break when JavaScript is unavailable for core authentication.
10. No production credentials or demo secrets may be committed.

## 9. Required tests and acceptance criteria

### Design and layout
- [ ] The application shell uses centralized semantic design tokens.
- [ ] The top bar is consistent and responsive.
- [ ] The sidebar has correct active/hover/focus states, section grouping, and collapse behaviour.
- [ ] The sidebar scroll region works independently and the page has no unwanted horizontal overflow.
- [ ] The asymmetric login page uses approximately 75/25 desktop composition, with a usability-based minimum form width.
- [ ] Mobile and tablet login layouts are usable without clipped controls.
- [ ] Branding works when a logo or background image is absent.
- [ ] Contrast and keyboard focus are adequate.
- [ ] Reduced-motion preferences are respected.

### Functional and security regression
- [ ] Every sidebar destination maps to a real, authorized route.
- [ ] Active navigation state is correct on nested routes.
- [ ] Sign-in, sign-out, CSRF, validation errors, rate limiting, and session behaviour are unchanged.
- [ ] Microsoft Entra SSO remains disabled/fail-closed until real credentials are configured.
- [ ] Tenant administrators, recruiters, candidates, and API principals retain their current authorization boundaries.
- [ ] Platform administration navigation remains restricted to authorized platform Super Admins.
- [ ] Synthetic persona shortcuts and demo credentials are not rendered in production.
- [ ] No tests are weakened or skipped to pass the redesign.
- [ ] All existing tests, Pint, Composer audit, configured static analysis, and the required MariaDB CI matrix pass.

### Verification
- [ ] Test at desktop (1440 px and 1280 px), tablet (768 px), and mobile (390 px and 360 px) widths.
- [ ] Verify keyboard-only navigation and focus states.
- [ ] Use browser screenshots or browser-based checks if an authorized browser is available. Clearly distinguish browser verification from HTTP checks or automated tests.
- [ ] Confirm the actual deployed commit and live behaviour if deployment is authorized. If not, report deployment as pending.
- [ ] Update FSD traceability and the release report with evidence.

## 10. Required deliverables

1. This specification saved in FSD/RECRUITMENT_AI_THEME_SIDEBAR_ASYMMETRIC_LOGIN_SPEC.md.
2. Implementation changes in a dedicated feature branch.
3. Automated tests for layout/authentication regressions where feasible.
4. Updated design/requirements traceability and test report.
5. A pull request with screenshots or visual evidence when available.
6. A final report identifying the exact files changed, tests and CI outcomes, branch/commit/PR, and deployment status.

## 11. Completion rule

A specification document or a passing unit-test suite alone does not mean the redesign is complete. Completion requires implemented responsive layouts, preserved authentication and authorization behaviour, test evidence, visual verification where available, and truthful deployment reporting.

**Proceed implementation-first. Do not stop after copying this document into the repository or returning a plan.**
