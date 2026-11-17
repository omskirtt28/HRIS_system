# HRIS Tailwind setup

My Team (`employee-team`) is the first Tailwind pilot. The HR dashboard also loads it after its legacy screen stylesheet.
The existing HRIS design system remains the visual baseline.

## Build

From the project root, use `npm ci` after checkout, then `npm run build:css`.
Use `npm run watch:css` only during development. On PowerShell with script
execution restricted, use `npm.cmd` instead of `npm`.

The build writes `public/assets/tailwind.css`. Commit the generated CSS when a
screen adopts it; hosting only needs the built CSS, not Node or node_modules.
Rebuild after changing template classes; do not use a runtime CDN compiler.

## Screen adoption

- Plan the target screen before editing its markup or loading the stylesheet.
- Use prefixed classes such as `tw:flex`, `tw:gap-4`, and `tw:md:grid-cols-2`.
- Keep full class strings in templates; do not concatenate utility fragments.
- Preflight is deliberately omitted to preserve existing defaults. Set border
  style explicitly when needed, for example `tw:border tw:border-solid`.
- Source scanning is limited to index.php, app PHP templates and asset JavaScript;
  add an explicit source if a future screen lives elsewhere.
- Existing unlayered HRIS CSS can override layered utilities; review conflicting
  selectors in the chosen screen rather than adding global important rules.
- Verify browser support before adoption: Tailwind v4 targets Safari 16.4+,
  Chrome 111+ and Firefox 128+.

Official references: [CLI](https://tailwindcss.com/docs/installation/tailwind-cli),
[no Preflight and prefix](https://tailwindcss.com/docs/preflight),
[source scanning](https://tailwindcss.com/docs/detecting-classes-in-source-files),
[browser support](https://tailwindcss.com/docs/upgrade-guide).

My Team combines prefixed layout utilities with unlayered .myteam components in
this input file to override existing unlayered roster styles without global resets.
The generated CSS must be rebuilt and uploaded alongside the PHP template/asset link.

## Additional UI libraries - 6 November 2026

Installed locally and exactly pinned: daisyui 5.7.50 (build-time devDependency)
and alpinejs 3.17.5 (runtime dependency), alongside Tailwind/CLI 4.3.4.
Install/reproduce with npm ci; install scripts were disabled during this setup.
These packages are installed only: daisyUI is not registered in the CSS input,
and Alpine is not bundled/copied to public assets or loaded by PHP templates.
Installing packages alone does not change the rendered HRIS screens.

Before a screen adopts daisyUI, use a component prefix and scoped theme root,
select only needed components, and preserve existing HRIS tokens/no Preflight.
Before a screen adopts Alpine, prepare a local public asset/build step, defer
its script, and check existing handlers, CSP, initialization and focus behavior.
Do not serve node_modules or add a second handler to the same interaction.

Backend research: Symfony offers separately installable components; Slim is
an HTTP/API microframework; Laravel is a full application framework. None was
installed: first choose the backend problem and assess PHP/extensions, routing,
authentication and deployment compatibility. No backend migration is selected.

References: https://daisyui.com/docs/config/,
https://alpinejs.dev/essentials/installation,
https://symfony.com/doc/current/components/index.html,
https://www.slimframework.com/docs/v4/,
https://laravel.com/framework/docs/12.x.
