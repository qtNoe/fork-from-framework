// Not-found handling in CanRenderView::render, driven by RenderProbeController.
// A missing VIEW is caught (BladeException) and re-rendered as the framework 500
// page, threaded through whatever layout the caller passed.
//
// Note: a missing LAYOUT is a separate, unhandled case (the 500 fallback extends
// that same missing layout and throws again, so it surfaces as a hard 500). It is
// intentionally not covered here: the malformed error response is slow and flaky
// to assert over HTTP, and hardening it is a framework change, not a test.
//
// Stateless renders: no cy.dbSeed().

describe('View not-found fallback', () => {

    // Framework 500 page markers (IncludedComponents/views/500.blade.php).
    const FIVE_HUNDRED = [
        'Oops! This page seems to be broken',
        'Sorry, we messed up!',
        'Take me back to my Website',
    ];

    it('renders the framework 500 page in place of a missing view', () => {
        cy.request({ url: '/RenderProbe/missingView', failOnStatusCode: false }).then((res) => {
            // The render is caught and swapped for the 500 body; the framework
            // does not raise the HTTP status, so this stays 200 with a 500 body.
            expect(res.status).to.eq(200);
            FIVE_HUNDRED.forEach((marker) => expect(res.body).to.include(marker));
        });
    });

    it('threads the 500 fallback through the caller layout (layout/empty: no chrome)', () => {
        cy.request({ url: '/RenderProbe/missingViewRaw', failOnStatusCode: false }).then((res) => {
            expect(res.status).to.eq(200);
            expect(res.body).to.include('Sorry, we messed up!');
            expect(res.body).to.include('Take me back to my Website');
            // Rendered through layout/empty, so no surrounding page shell.
            expect(res.body).to.not.match(/<!doctype/i);
            expect(res.body).to.not.match(/<html\b/i);
        });
    });
});
