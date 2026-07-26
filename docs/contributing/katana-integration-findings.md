# Katana integration findings

Working notes from integrating [Katana](https://github.com/katanaphp/blade) (the standalone Blade
compiler, since moved from `soysudhanshu/katana` to `katanaphp/blade`) as ZubZet's render engine
(issue [#145](https://github.com/zubzet/framework/issues/145)). The `soysudhanshu/katana` Composer
package still resolves, now sourced from the new repo.

**Purpose:** the point of this WIP is *discovery* - drive a real integration far enough to
surface exactly which hooks Katana still needs, so the render-engine maintainer can finalise
[katana#53](https://github.com/soysudhanshu/katana/issues/53) before the final implementation.
It is deliberately not a finished renderer.

## Upstream status (2026-07-16)

The maintainer picked up [katana#53](https://github.com/soysudhanshu/katana/issues/53) and the
change-points below are landing. Current mapping of findings → upstream:

| # | Change-point | Upstream status |
| - | ------------ | --------------- |
| 3 | PHP 8.0 incompatibility | **Fixed upstream.** [katana#56](https://github.com/soysudhanshu/katana/pull/56) merged to `master`: drops `readonly`, uses `sha1` instead of `xxh64`, declares `php >=8.0`. `katana-php80-compat.patch` **deleted**; the branch now requires `dev-master` (no 0.1.x release carries #56 yet). |
| 1 | `@extends` drops render data | **Fixed upstream.** [#57](https://github.com/katanaphp/blade/pull/57) merged (maintainer's `@include`/`tempContextData` approach). `katana-extends-forward-data.patch` **deleted** — with #56 already merged, `patches/` is now empty and the container no longer patches `vendor/`. |
| 4, 7 | single view path / no view finder, cache | [katana#55](https://github.com/soysudhanshu/katana/pull/55) **open** — adds `Config` + abstract `ViewFinder` + `FileSystemViewFinder`, an *ordered* finder list that also drives `@include`/`@extends`/component resolution. Removes the per-root engine and the cross-root-include limitation. Not merged; **prototyped and deferred** (see note below). |
| 5 | no render-from-string / layout selection | [katana#34](https://github.com/soysudhanshu/katana/issues/34) planned; `ViewFinder::getContents` (in #55) already lets a finder build views on the fly. |
| 2 | inheritance state leaks | acknowledged in the #53 thread (engine lifecycle); tied to #57. |
| 9 | `{{ }}` compiles inside `<?php ?>` | **Fixed on `master`** by [#61](https://github.com/katanaphp/blade/pull/61). #61 regressed short echo tags; reported as [#62](https://github.com/katanaphp/blade/issues/62) and fixed by [#63](https://github.com/katanaphp/blade/pull/63) (both merged), so the branch now pins `dev-master#4c585561`. |
| 10 | compiled cache has no engine version | Not upstream. Worked around in the adapter (cache dir keyed on the installed engine reference). |

The engine repo moved to [`katanaphp/blade`](https://github.com/katanaphp/blade); the
`soysudhanshu/katana` Composer package still resolves (Packagist source now points at the new repo),
so the branch just bumps the pin to **`dev-master#4c585561`**. With #56 and #57 both merged, **no
patches remain**: `vendor/` is used as-installed and the old "patch `vendor/` after `composer install`"
step is gone.

The synthetic-child-on-disk is **gone**: since the views migrate to real template inheritance
(`@extends($layout)`, change-point 5 below), the adapter renders the view file directly. Until #55/#34
merge, it still keeps its per-root engine (the remaining simplification).

### `master` regressed `<?=` (#61), we fixed it upstream and are back on `master`  — **RESOLVED (#63 merged)**

**Outcome:** reported as [#62](https://github.com/katanaphp/blade/issues/62), fixed by
[#63](https://github.com/katanaphp/blade/pull/63) (one line: map `T_OPEN_TAG_WITH_ECHO` to
`@php echo`, so `<?=` compiles exactly like `<?php echo`), merged to `master`. The branch is pinned at
**`dev-master#4c585561`** again and the whole e2e suite is green on it. The history below is kept
because it is the reasoning behind the fix.

`master` (`e539464`) fixes change-point 9: Blade no longer compiles `{{ }}`, `{!! !!}` or `{{-- --}}`
inside `<?php ?>` / `@php` blocks, so literal mustaches in PHP strings need no escaping there. That
would let the migrator's neutraliser skip PHP blocks entirely (escaping there would emit a stray `@`).

However #61 implements this by rewriting PHP tags into directives:

```php
// Compiler::replacePhpTagsWithDirectives()
T_OPEN_TAG  => "@php",
T_CLOSE_TAG => "@endphp",
default     => $token[1],
```

`T_OPEN_TAG_WITH_ECHO` (`<?=`) is not handled, so the opening `<?=` is emitted literally while its
closing `?>` still becomes `@endphp`, leaving an unterminated PHP block. Minimal repro:

```blade
<a href="<?= "x" ?>y">z</a>   {{-- ParseError: unexpected token "@" --}}
```

Our migrated views use `<?=` heavily (the legacy views did), so `e539464` broke
`database/rows`, `layout/z_admin_layout` and the e2e `login` view, and `/z/edit_user` 500s. Reported as
#62 and fixed by #63 (see above); `<?=` and `<?php echo` now compile identically.

**Composer caveat:** a `dev-master#<ref>` pin is honoured only in the *root* package. Because the
framework is a dependency of the app, the pin does not propagate, and the e2e app resolves plain
`dev-master` to whatever HEAD is. Tracking `dev-master` is therefore not viable for consumers; this
argues for a tagged release carrying #56/#57.

### #55 (Config/ViewFinder) prototyped, then deferred

The adapter was rewritten against the #55 branch (`feature/config-object`) to prove the payoff — one
engine with an ordered finder list (userspace → framework), the synthetic child served from memory via
a `StringViewFinder`, and cross-root `@include`/`@extends`/components resolving through the same list.
It renders correctly on **PHP 8.4**, but the rewrite was **reverted**: #55 branched *before* #56, is
authored for 8.4, and adds more workarounds than it removes.

- **8.0 floor broken:** `Blade::viewExists()` uses `array_any()` (**PHP 8.4-only**); plus `readonly`
  (`Blade`, `Slot`), `hash('xxh64')` (8.1) and `#[Override]` (8.3). Because #55 predates #56 it also
  lacks the 8.0 fixes already on `master`.
- **`$cachePath` never initialised** for `new Blade(null, $cache, $config)` — the constructor only
  sets `cachePath` alongside a `viewPath`, so `getCachedViewPath()` fatals on the config-only path.
- **Cache-identifier bug:** production `getViewIdentifier()` calls `filemtime()` on the view *name*,
  not the finder-resolved path, so it is wrong for any `ViewFinder`; the adapter had to force
  `MODE_TESTING` (content-addressed) to render at all.
- Still needs the same `@extends`-forwards-data patch (the bug is unchanged on #55).

Net: the on-disk compose file merely moves into a `StringViewFinder`, and the real win (cross-root
includes) is offset by three new #55 bugs plus a broken 8.0 floor. Revisit once #55 is rebased on
post-#56 `master`, drops `array_any`, and fixes the `cachePath`/identifier bugs. Filed as feedback on
the #53 thread.

#### Re-checked 2026-07-09: the blockers are fixed, #55 is now adoptable

The maintainer rebased #55 and fixed every point above, so the earlier deferral no longer holds:

- rebased onto post-#56/#57 `master` (so no PHP 8.0 patch, no `@extends`-data patch);
- `array_any()` and `hash('xxh64')` are gone; the only `readonly` hits are the `@readonly`
  HTML directive's string literals, not the keyword;
- `MODE_PRODUCTION` / `MODE_TESTING` **dropped**, with a single content-addressed identifier
  `sha1($name . lastModified)`, so the adapter no longer has to force `MODE_TESTING`;
- `$cachePath` is now assigned independently of `$viewPath`;
- `getViewIdentifier()`/`getViewContents()` resolve through `Config::getViewFinders()`, fixing the
  cache-identifier bug.

`ViewFinder` (`viewExists` / `lastModified` / `getContents`) plus `Config::addViewFinder()` gives the
ordered userspace → framework chain we need, and it drives `@include`/`@extends`/component resolution,
which removes change-points 4 and most of 5.

Remaining nits, neither a blocker: `FileSystemViewFinder` uses `#[Override]` (a PHP 8.3 class), which
is **runtime-safe on 8.0** (verified on 8.0.30; it only fails if something reflects and instantiates
the attribute), and #55 does not yet contain #61. Adopt once `master` has a fixed #61 and #55 is
rebased on it.

Originally validated against **Katana 0.1.0** on **PHP 8.0.30** (the framework's minimum), rendering
the real framework and e2e-app views migrated to `.blade.php`; view+layout composition and
data-to-layout were re-smoke-tested on the pin `dev-master#4c585561` **with no patches** (the
merged #57 forwards `$opt` to the layout natively).

## What was validated

- The v1.3 migrator converts every legacy `return[...]` view **and** layout to `.blade.php`.
  An oracle renders each converted document through real Katana and diffs it against the legacy
  closures' own output: **47/47 renderable views byte-identical**; **full view+layout composition
  (external injection): 42 byte-identical, 8 whitespace-only (content identical), 0 content diffs.**
- End-to-end on the dockerised e2e stack (PHP 8.0): pages render through Katana; the
  `core/framework-views` (12/12), `core/layout` (16/16) and `core/blade-compat` (2/2) specs pass.
- A dedicated compatibility probe (`core/blade-compat`) proves the migration keeps literal `{{ }}`,
  `{!! !!}` and `{{-- --}}` verbatim, that CSS `@media` passes through, and that an anonymous
  `<x-component>` renders through the adapter.

### Migration escaping notes (what the converter emits)

- Literal `{{ }}` / `{!! !!}` are escaped with Blade's own `@{{` / `@{!!` forms.
- Literal `{{-- --}}` **cannot** use `@{{--`: Katana strips comments *before* echo-escaping, so the
  converter wraps them in `@verbatim ... @endverbatim` instead. Raw `<?php ?>` still executes inside
  `@verbatim`, so this stays output-preserving.
- `@php ... @endphp` is equivalent to `<?php ... ?>` (it compiles to exactly that); migrated views
  keep raw `<?php ?>` and pass `$opt` as a single datum, so no view-body rewriting is needed.

## Katana change-points (what katana#53 should cover)

Ranked by how much they block a clean integration. "Framework workaround" is what the adapter has
to do *today* to cope; each workaround is the tax we pay for a missing hook.

### 1. `@extends` does not forward render data to the parent layout  — blocker — **RESOLVED upstream (#57 merged)**

Fixed on `master` (the `@include`/`tempContextData` approach); the patch is gone. Recorded for history.

`TemplateInheritanceRenderer::output()` renders the parent template with **no data**:

```php
// TemplateInheritanceRenderer.php
public function output(): void {
    ...
    echo $this->blade->render($this->template);   // <- no $data
}
```

The child view receives the data (via `renderContents()`'s `extract($data)`), but the layout is
rendered blind, so any `$var` a layout uses (here `$opt` for root/title/essentials) is null and
the layout fatals. `@include` already threads data (`tempContextData`/`withDefault`); `@extends`
does not.

- **Fixed upstream (#57):** `output()` now forwards the view's data to the parent via
  `tempContextData`/`withDefault`. Our earlier `patches/katana-extends-forward-data.patch` instead
  stored the render data on the inheritance renderer and passed it in `output()`, **saved and
  restored** around each `renderContents()` include, otherwise a nested render (an `<x-component>`
  or `@include`, which re-enter `renderContents` with their own data) clobbers the shared
  `contextData` before the outer `output()` reads it, and the layout loses `$opt`. This bug bit a
  component-using view and the admin layout until the save/restore was added.
- **#53 mapping:** the "global state / service injection" story only works if the layout shares the
  child's data scope. Tied to finding #2 (state lives on the shared instance).
- **Framework workaround:** none possible without the patch (layouts genuinely need the data).

### 2. Template-inheritance state leaks across top-level renders  — blocker (also a Katana bug)

`@section` content is stored on the (shared) `Blade` instance and never reset between renders, and
`startSection()` skips a section that is already set:

```php
public function startSection(string $section, string $inlineContent = ''): void {
    ...
    if (isset($this->sections[$section])) return;   // keeps the FIRST render's section
    ...
}
```

Rendering view A then view B through the same `Blade` instance makes B reuse A's `body`/`head`.
Observed directly: three different views all rendered the first view's body.

- **Fix:** reset inheritance state per top-level render, or don't hang it off the shared instance.
- **#53 mapping:** any long-lived engine handed to a framework must be safe to render N times.
- **Framework workaround:** a fresh `Blade` per render (the on-disk compile cache is still shared).

### 3. PHP 8.0 incompatibility  — major — **RESOLVED upstream (katana#56)**

Fixed on `master` and consumed via `dev-master`; the patch is gone. Recorded for history.

The framework supports `php >=8.0 <8.6`; Katana 0.1.0 does not run on 8.0:

- `public readonly` properties (PHP 8.1+) in `Blade.php`, `Slot.php`, `CompileAtRules.php`
  → `ParseError` on 8.0.
- `hash('xxh64', ...)` (algorithm added in PHP 8.1) in `Blade.php::getViewIdentifier()`
  → `ValueError` on 8.0.

Katana declares no `require.php` constraint, so Composer installs it on 8.0 and it fails at runtime.

- **Fix (done):** #56 drops `readonly`, swaps `xxh64`→`sha1`, and declares `"php": ">=8.0"` — exactly
  what `patches/katana-php80-compat.patch` did, so the patch was removed once the branch moved to
  `dev-master`.
- **#53 mapping:** a "standalone Blade for any PHP project" should state and honour its floor.
- **Framework decision:** kept the 8.0 floor; Katana is now 8.0-compatible upstream.

### 4. Single view path, no pluggable view finder  — major

`new Blade($viewPath, $cachePath)` takes exactly one root. The framework resolves views
**userspace-overrides-then-framework-fallback** (two roots), so a single root cannot express the
lookup chain. This is exactly katana#53's `AbstractViewFinder` / `addViewPath`.

- **Framework workaround:** one `Blade` per root, picked by where the layout resolves; the view is
  rendered by absolute path via `renderViewFile()`, so it may live under the *other* root while its
  `@extends` still resolves against this one. **Limitation:** a userspace view that `@include`s a
  framework partial (or vice-versa) will not resolve across roots. Framework-published *components*
  do work across roots, via `addAnonymousComponentPath()` (see change-point 10).

### 5. No render-from-string / no programmatic layout selection  — major — **SOLVED framework-side (no upstream change needed)**

Originally the layout was chosen *externally* (per request / `HandlesDefaultLayout`) rather than via an
in-view `@extends`, so the adapter synthesised a child `@extends('<layout>') ...` on disk per
(view, layout) - katana can only render **files**, with no `renderString()` and no "render view X into
layout Y".

**Resolved by inverting it:** the migrator now emits real template inheritance, so the *view* owns its
layout and the layout name is just render data:

```blade
@extends($layout)
@section("head") ... @endsection
@section("content") ... @endsection
```

`$layout` is the dotted view name of the layout the framework picked, so the same view still works for
the default, a per-request push/pop, or an explicit layout - and layouts outside `layout/` (e.g. the
mail layouts at `rendering/mail_layout`) work too, which a hardcoded `"layout.$layout"` prefix could
not express. The synthetic child, its on-disk compose file and the section-detection are all gone.

- **Upstream value:** a `renderString()` / `renderWithLayout()` would still help engines whose hosts
  cannot migrate their views, but ZubZet no longer needs it.

### 6. `Blade` is `final`  — minor

`final class Blade` blocks subclassing, so a framework can't override one method to inject behaviour
and must compose around the class. Consider non-final or documented extension points.

### 7. Cache handling  — minor

Katana writes compiled views with `file_put_contents` and assumes the cache dir exists (no `mkdir`),
keys on `path+filemtime`, and exposes no clear/invalidate API. Issue #145 asks for a PSR file cache;
Katana's cache is not PSR and is not injectable.

- **Framework workaround:** adapter creates the cache dir.

### 9. Compiled-view cache is not keyed on the engine  — minor (but bites on upgrade)

`getViewIdentifier()` is `sha1($path . filemtime($path))`, with no compiler/engine version. Upgrading
Katana therefore reuses templates compiled by the **previous** compiler: after moving past #57, cached
views still emitted the old `$template_renderer->output()` (without `withDefault(...)`), so layouts
silently rendered without their data until the cache was cleared. Nothing in the source changed, so
`filemtime` never moved.

- **Framework fix:** `CanRenderView::compiledViewCacheKey()` mixes the installed engine reference
  (`Composer\InstalledVersions::getReference()`) into the cache directory name, so an engine upgrade
  starts from a clean cache.
- **Upstream suggestion:** include a compiler version in the identifier, or expose a cache-clear API.

### 10. No component namespace for package-published components  — major (TODO: waiting on #55)

Since 1.3.0 the framework publishes its own components (`<x-zubzet.head/>` / `<x-zubzet.body/>`, the
layout essentials) into every render by registering a shared path with `addAnonymousComponentPath()`.
Katana has no package/namespace concept for components, so those sit in the same flat namespace as the
application's own: the component-tag regex is `[a-z0-9-.]*` (no colons), and component resolution
searches the userspace path **first**, so an app component named `head` would silently shadow the
framework's. Laravel solves this with `<x-package::component/>` (`anonymousComponentNamespace()`).

- **Framework workaround (shipped):** keep them in a `zubzet/` sub-directory and address them dotted
  (`<x-zubzet.head/>` -> `components/zubzet/head.blade.php`). Shadowing then needs a deliberate
  `components/zubzet/` directory rather than a common word, but it is a convention, not a namespace.
- **Upstream fix:** [katana#66](https://github.com/katanaphp/blade/pull/66) (**WIP, open**) adds
  `addAnonymousComponentNamespace($ns, $pathOrFinder)`, an optional `(?:::[a-z0-9-.]+)?` in the three
  component-tag regexes, and an early branch in `Component` so a `ns::name` only ever resolves inside
  its own namespace (never falls back, never shadowed). Green: 7 new tests, full katana suite
  225 tests / 293 assertions OK. Risk is **low** - every change is additive, and a `::` name was
  previously unrepresentable (the tag regex had no colons), so no existing template can reach the new
  path.
- **Status (in progress):** #55 (`Config` / `ViewFinder`) has since landed and is in the pinned
  build, so the original blocker is cleared. A component-namespace PR (the `<x-zubzet::head/>`
  support, successor to #66) is **actively in the works upstream**. Once it lands, the framework's
  own components should move to a real `zubzet::` namespace so they can never be shadowed by an app
  component (today they rely on the `components/zubzet/` sub-directory convention).
- **TODO once #55 and #66 land:** bump the pin past them and switch the essentials from
  `<x-zubzet.head/>` to `<x-zubzet::head/>`. That is a one-line change in the converter
  (`LegacyViewConverter::essentialsTags()`), the two component files' location, and the six layouts.
- **Also found while prototyping:** an unresolved component (namespaced *or* plain) surfaces as a raw
  PHP `Error` ("Object of class Blade\Component@anonymous could not be converted to string") and leaves
  an output buffer open, rather than a `BladeException`. Pre-existing; a clean not-found error would be
  a good follow-up.

### 8. `e()` helper  — non-issue (recorded so it isn't re-litigated)

Katana namespaces its helper (`Blade\e`) and compiles `{{ }}` to fully-qualified `\Blade\e(...)`, so
there is **no collision** with the framework's global `e()`. The framework's `e()` now delegates to
`\Blade\e()` (keeping its historical `strip_tags()` + null passthrough) so both escape identically.

## How the framework integrates today (the lean adapter)

- `src/Rendering/Katana/` - the only Katana-specific glue: `Engine.php` (the adapter: a fresh `Blade`
  per render (change-point 2), rooted where the layout resolves (change-point 4), the framework's own
  component path registered (change-point 10), and `$layout` handed over as render data), `Hooks.php`
  (framework directives/callbacks bound to the request, currently `@auth` / `@guest`, expected to grow),
  and `ExactFileViewFinder.php` (the pinned finder). It no longer rewrites or composes views at all.
- `src/Rendering/CanRenderView.php` - `resolvePath()` now targets `.blade.php`; `render()` still
  builds the same `$opt` contract. No second renderer, no closure fallback (per the "Blade-only"
  decision), and no `layout_essentials_*` closures: the essentials are the framework's own Blade
  components (`<x-zubzet.head/>` / `<x-zubzet.body/>`), so `layout_essentials.php` is gone.
- `src/Support/Helpers.php` - `e()` delegates to `\Blade\e()`.
- `patches/` - **removed**. Both Katana fixes are upstream now (#56 PHP 8.0, #57 `@extends` data), so
  `vendor/` is used exactly as `composer install` leaves it; there is no longer a patch step.

### Adapter internals (detail moved out of the source comments)

The adapter classes keep only short comments; the reasoning lives here.

**`ExactFileViewFinder` — pinning a resolved file to a synthetic name.** The framework resolves
a view and its layout to absolute paths through its own userspace-overrides-framework
precedence, and sometimes deliberately forces the framework copy even when a userspace override
exists. Katana resolves views *by name*, so registering the real roots would let Katana
re-resolve that name with its own precedence and possibly pick a different file. `Engine`
therefore wraps each already-resolved file in an `ExactFileViewFinder` under a synthetic,
path-unique name (`__zubzet_entry_<md5>` / `__zubzet_layout_<md5>`) and registers it *before* the
`FileSystemViewFinder` for the layout root, so Katana renders exactly the chosen file while the
root still resolves the layout's own `@extends` chain, includes and components.

**Compiled-view cache collision (why the synthetic name also helped).** Katana keyed a compiled
view on `sha1(name . lastModified)` with **no path**, so two files sharing a view name and mtime
collide in the shared on-disk cache — exactly the userspace-override vs framework-copy case on a
fresh checkout (equal mtimes). Pinning each file to a path-unique synthetic name sidestepped it.
Fixed upstream by [katanaphp/blade#71](https://github.com/katanaphp/blade/pull/71), which adds
`ViewFinder::identity()` (mixed into the cache key). `ExactFileViewFinder::identity()` returns the
resolved file path (already unique per source). It is intentionally declared **without
`#[Override]`** so the class stays valid both on the current `master` (no such abstract yet) and
once #71 lands on the pinned branch — `#[Override]` is enforced on PHP 8.3+ and would fatal
against a master that lacks the abstract.

**`@auth` / `@guest` binding.** `Hooks` (wired in by `Engine` via `Hooks::register($config)`) binds
Katana's auth callback (`Config::setAuthCallback`, which registers both `@auth` and `@guest`) to the
current request.
Laravel's directive argument is a *guard* name, which has no analogue here, so the argument is
reinterpreted as a framework **permission** (dotted, wildcard-aware); `@guest` is the negation:

- `@auth` — is the user logged in
- `@auth("x.y")` — has permission `x.y`
- `@guest` / `@guest("x.y")` — negation of the above

**View-name resolution (`CanRenderView::resolvePath`).** Resolution tries candidates in order.
The literal form is tried first and preserves the historical behaviour: slash paths like
`admin/users`, already-rooted absolute paths, and any real dotted filename. Only if that misses,
and the name has a dot but no slash, is a bare Laravel-style dotted name (`admin.users`) retried
with dots as path separators. The dotted form is strictly a fallback, so nothing that already
resolves changes. (Katana's own `FileSystemViewFinder` splits dots natively, so if resolution
ever moves fully into the finders, this fallback becomes redundant.)

The compiled-view cache directory is additionally keyed on the installed engine reference; see
change-point 9.

## Migrator (version-migrator v1.3)

`LegacyViewConverter` (tokenizer-based) + `ViewMigration` modifier + `V1_3_0` step convert
`return[...]` views/layouts to `.blade.php`:

- body-only view → straight content; head(+body) → `@section('head')` / `@section('body')`.
- layout `$body(...)` / `$head(...)` calls → `@yield('body')` / `@yield('head')`; everything else
  (including `$opt["layout_essentials_*"]`) stays raw PHP.
- literal `{{`, `{!!`, `{{--` are escaped (`@{{` ...) so Katana emits them verbatim.

Run against the e2e app: **36 views/layouts converted, 0 `.php` left**, output byte-identical to the
validated converter.
