# Loading Overlay
Since version **1.4.0**, `Z.js` ships a loading overlay with an animated spinner. It replaces the former `loadCircle.css` and the `#loading` element in the login views.

```js
// Cover the whole page
Z.loader.show();
Z.loader.hide();

// Cover a single element (id or element reference)
Z.loader.show("result-card");
Z.loader.hide("result-card");
```

- The required css is injected by `Z.js` on first use - no stylesheet include needed.
- `show()` keeps a single overlay per target, repeated calls have no effect.
- The login presets (`Z.Presets.Login`, `Signup`, `ForgotPassword`) show the overlay automatically while their request is running.

**Migrator note:** `assets/css/loadCircle.css` has been removed. Views that linked it or provided their own `<div id="loading">` can drop both - the presets no longer look for a `#loading` element.
