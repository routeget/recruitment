# Recruitment AI — QuickBook ERP Design Replication Specification

**ID:** RAI-UI-REF-QBERP-001  
**Target:** `routeget/recruitment`  
**Status:** Authoritative implementation specification  
**Reference:** QuickBook ERP UI patterns, recorded here for an implementation agent that has no access to other repositories.

## 1. Purpose and mandatory boundary

This document is the complete design handoff. Antigravity must **not** attempt to open, clone, search, or request access to QuickBook ERP or any other repository. Implement the visual patterns below in the Recruitment AI repository only.

Replicate QuickBook ERP's enterprise theme, compact grouped sidebar, theme-token architecture, and asymmetric login composition. Do not copy QuickBook branding, logo, text, finance/accounting navigation, routes, workflows, or business logic. Recruitment AI retains its identity, routes, permissions, tenant isolation, authentication and all current business behaviour.

## 2. Reference implementation facts

The QuickBook ERP UI that informed this specification used:
- Laravel Blade templates, Bootstrap 5.3, locally served Inter fonts, and locally served Bootstrap Icons.
- Microsoft Dynamics 365 Finance / Fluent-inspired theme tokens and centralized branding overrides.
- A deep navy/blue application header; white/light neutral surfaces; restrained dividers; compact enterprise spacing.
- A white sidebar approximately **260 px** wide, independently scrollable, with a thin right divider.
- Compact icon-and-label navigation rows, muted uppercase section headings with letter spacing, collapsible groups, and indented sub-navigation.
- Active navigation with a pale blue selected surface, primary blue text, stronger font weight and a **3 px left indicator**. Hover is a subtle pale blue.
- A shell-level search style with keyboard hint/results styling, suitable only if connected to real authorized destinations.
- A login page using Bootstrap's **col-lg-7 / col-lg-5 split (approximately 58.3% / 41.7%)**. The left pane has a deep blue gradient and a low-opacity SVG wave anchored at the bottom; the right pane is white and contains a constrained login form.
- Dynamic branding/theme tokens for primary actions, header colours, surfaces, borders, selected/hover states, typography, and Bootstrap colour aliases. The application can provide logo, favicon, app name and footer text; missing optional branding has a fallback.

These facts are fully restated here. No source-repository access is required.

## 3. Design direction and default palette

Use a restrained Microsoft Dynamics 365 / Fluent-inspired enterprise visual system: compact navy header, white/light sidebar, charcoal text, neutral dividers, primary blue actions, pale-blue active navigation and a clean asymmetric login page.

Fallback values below are defaults only. Prefer an existing approved Recruitment AI theme/branding setting where available.

| Semantic purpose | Fallback |
|---|---|
| Primary action / links | `#0078D4` |
| Header emphasis / hover | `#004578` |
| Header dark | `#002050` |
| Main surface | `#FFFFFF` |
| Canvas | `#F5F5F5` |
| Secondary surface | `#F1F5F9` |
| Main text | `#323130` |
| Secondary text | `#605E5C` |
| Input border | `#D2D0CE` |
| Divider | `#EDEBE9` |
| Hover surface | `#F3F9FD` |
| Selected row | `#EFF6FC` |
| Selected/focus control | `#DEECF9` |
| Success text/background | `#107C10` / `#DFF6DD` |
| Body font | Existing local Inter, otherwise existing system stack beginning with Segoe UI |

Create or extend one central theme stylesheet using existing project conventions. Use semantic tokens equivalent to:
- `--color-primary-actions`, `--color-header-emphasis`, `--color-header-dark`
- `--color-selected-row-bg`, `--color-selected-control`, `--color-hover-bg`, `--color-hyperlinks`
- `--color-text-main`, `--color-text-secondary`, `--color-text-high-emphasis`, `--color-disabled-controls`
- `--color-surface-main`, `--color-bg-canvas`, `--color-surface-secondary`
- `--color-border-input`, `--color-border-divider`
- semantic success, warning, error and info foreground/background tokens
- `--d365-font-title`, `--d365-font-body`, `--d365-font-field-label`
- layout tokens for sidebar width (260 px), header height (around 48 px), navigation row spacing and control radius.

Map tokens to existing Recruitment AI branding/configuration if possible. Do not create a competing theme engine. Validate configured colour values before emitting CSS; never insert arbitrary untrusted CSS. Maintain readable contrast. Do not add remote fonts, icon libraries or Bootstrap copies if compatible local dependencies already exist.

## 4. Application shell and top bar

- Use a full-width deep navy/blue top bar with high-contrast controls, targeting about **48 px** high on desktop.
- Preserve Recruitment AI's own logo/name, authorized navigation controls, current user/context information, and existing sign-out action.
- A search control is optional and must be connected to real, authorized destinations; never ship decorative or dead search.
- Keep the main content flexible, responsive and free of horizontal overflow.
- Preserve current breadcrumbs, alerts, security/context banners and skip-to-main-content support where present.
- Keep sign-out on the existing CSRF-protected server route. Do not replace current layout or route contracts with a static mockup.

## 5. Sidebar panel specification

### Appearance and geometry
- Expanded desktop width: **260 px**.
- White or theme main-surface background; subtle one-pixel right divider.
- Sidebar starts below the top bar and has its own vertical scroll region.
- Compact, consistent navigation rows with aligned icons and labels; reference padding is around 0.5 rem vertical and 0.85 rem horizontal, with roughly 0.65 rem icon/text gap.
- Use modest 2–4 px corner radii rather than oversized pills.
- Section headers are uppercase, muted secondary text, semibold/bold, with modest letter spacing (about 0.05 em).
- Nested items are indented but remain readable and accessible.

### States and behaviour
- Hover: subtle pale-blue/neutral background and primary-blue text.
- Active: pale-blue selected background, primary-blue text, stronger weight and a **3 px left accent**. Avoid label shifting when the accent appears.
- Focus: visible keyboard indicator that is not conveyed by colour alone.
- Groups collapse/expand with a chevron and accessible control exposing `aria-expanded`; keyboard activation must work. Expand the parent of the active route when practical.
- Desktop sidebar is sticky/fixed below the top bar and scrolls independently.
- On tablet/mobile, use an accessible off-canvas drawer or current project equivalent with visible toggle, close action, backdrop, Escape-key handling, focus management, and no keyboard trap. Prevent horizontal overflow and respect reduced-motion preferences.

### Navigation integrity
- Use Recruitment AI's actual routes and existing server-side role/permission checks.
- Preserve tenant isolation and the platform Super Admin visibility condition.
- Do not copy QuickBook finance/accounting menu names or labels.
- Active matching must work for index, detail, edit and nested routes.
- Every visible item must lead to a real destination. Hiding a link is not authorization; server middleware/policies remain authoritative.
- No fake links such as `href="#"`, dead buttons or placeholder destinations.

## 6. Asymmetric login page

### Desktop: QuickBook's 7/5 split
At the Bootstrap large breakpoint and above (nominally 992 px), use a two-panel layout equivalent to **col-lg-7 + col-lg-5**, approximately **58.3% brand panel / 41.7% form panel**. Do **not** use a 75/25 split.

**Left brand panel**
- Fill available viewport height; deep blue gradient from `#002050` to `#004578`.
- Centre a suitable Recruitment AI icon/approved illustration, product headline and concise product-specific supporting statement.
- Use white heading text and muted white supporting text.
- Add a simple inline SVG wave anchored to the bottom at roughly 10–18% opacity. It must be decorative (`aria-hidden="true"`, pointer-events disabled) and must not interfere with text or interaction.

**Right form panel**
- White surface, vertically centred where viewport height permits, with responsive padding.
- Form container max width around **440 px**.
- Display Recruitment AI's configured logo/name, falling back safely when absent.
- Keep visible labels, real fields, validation/error states, remember-me behaviour, password reset route and any already-supported SSO control.
- Inputs use a neutral one-pixel border, modest radius and visible blue focus ring.
- Primary submit button uses configured blue, white text and a clear hover state.
- Footer/legal text stays in normal flow or is positioned safely; it must not overlap or clip the form on short screens.

### Tablet and mobile
- Below the large breakpoint, switch to a single-column form experience; hide the large brand panel or reduce it to a compact brand header.
- Form occupies available width with comfortable padding and readable maximum width.
- Page remains vertically scrollable. Do not lock overflow so errors or submit controls become inaccessible.
- Verify short-height screens, landscape, 200% zoom and mobile virtual keyboards. No horizontal scrolling.

### Security boundary
Preserve existing login endpoints/contracts, CSRF, rate limiting, session behaviour, password autocomplete semantics, validation, password reset and SSO fail-closed behaviour. Do not add fake authentication, hard-coded credentials or client-side authorization. If the current page has demo-persona shortcuts, ensure existing environment gating is correct and synthetic credentials/quick-fill controls never render in production. Never put passwords or secrets in this UI specification, screenshots, logs or commits.

## 7. Required implementation sequence

1. Work only in `routeget/recruitment`. This document is the complete reference; do not access other repositories.
2. Inspect the latest approved `main`, current layout/login/controller, CSS/JS assets, fonts/icons, routes, branding/configuration, authorization and existing tests.
3. Create a dedicated feature branch from latest approved `main`; do not overwrite unrelated work.
4. Implement the theme, shell, sidebar and login in the existing Laravel Blade/Bootstrap architecture. Reuse current dependencies.
5. Preserve routes, authentication, roles, tenant boundaries, data contracts and business workflows.
6. Add/update tests for shell/login rendering, navigation permissions, active route behaviour and demo-panel environment gating.
7. Run the prescribed test suite, formatter, static analysis, dependency/security checks and build steps. Never weaken or skip tests to get a green result.
8. If an authorized browser is available, capture and inspect screenshots at **1440, 1280, 768, 390 and 360 px**; verify keyboard-only use and reduced motion. Distinguish browser checks from unit tests and HTTP checks.
9. Open/update a PR with changed files, test evidence, screenshots where available, risks and limitations.
10. Deploy only through the authorized project release process. Report a deployed SHA only after verification.

## 8. Acceptance checklist

### Visual
- [ ] Centralized semantic tokens used consistently.
- [ ] Compact responsive navy/blue top bar.
- [ ] Approximately 260 px white/light independently scrolling sidebar with subtle divider.
- [ ] Grouped navigation, uppercase muted headers, compact rows, indentation, collapse controls, hover/focus/active states.
- [ ] Active row has pale-blue surface, blue text and slim left accent.
- [ ] Login uses the **7/5 split**, blue gradient left pane, subtle bottom wave, white right form pane.
- [ ] Mobile/tablet form has no clipped controls, errors or horizontal overflow.
- [ ] Missing optional branding has a graceful fallback.
- [ ] Contrast, focus, zoom, short screens and reduced motion are addressed.

### Functional/security
- [ ] Existing sign-in/sign-out, CSRF, validation, password reset, session and SSO behaviour preserved.
- [ ] Sidebar links are real routes; current role/permission and tenant checks remain intact.
- [ ] Unauthorized users do not see privileged navigation; server-side protection remains authoritative.
- [ ] Demo credentials/persona shortcuts are unavailable in production.
- [ ] No secrets, candidate data or tenant data are exposed.
- [ ] No existing tests removed, skipped or weakened.

### Evidence/release
- [ ] Test, formatting, static analysis and applicable security check results recorded.
- [ ] Visual/browser checks accurately labelled and screenshots attached when available.
- [ ] PR includes implementation summary, known limitations and deployment status.
- [ ] Deployment SHA reported only when actually verified.

## 9. Completion rule

A plan, specification or mockup is not completion. Completion requires actual Recruitment AI UI changes, preserved application behaviour, test evidence, responsive visual verification where available, and an honest PR/release report.

**Implement the design. Do not attempt to access QuickBook ERP or any other repository; all required reference information is contained here.**
