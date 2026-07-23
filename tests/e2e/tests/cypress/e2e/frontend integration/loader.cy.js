describe('Z.loader', () => {

    beforeEach(() => cy.visit('/Frontend/loader'));

    it('shows and hides the fullscreen overlay', () => {
        cy.window().then((win) => win.Z.loader.show());
        cy.get('body > .z-loader').should('be.visible');
        cy.get('.z-loader-spinner').should('be.visible');

        cy.window().then((win) => win.Z.loader.hide());
        cy.get('.z-loader').should('not.exist');
    });

    it('keeps a single overlay on repeated show calls', () => {
        cy.window().then((win) => {
            win.Z.loader.show();
            win.Z.loader.show();
        });
        cy.get('.z-loader').should('have.length', 1);

        cy.window().then((win) => win.Z.loader.hide());
        cy.get('.z-loader').should('not.exist');
    });

    it('scopes the overlay to a target element', () => {
        cy.window().then((win) => win.Z.loader.show('loader-target'));
        cy.query('loader-target').find('.z-loader').should('be.visible');
        cy.get('body > .z-loader').should('not.exist');

        cy.window().then((win) => win.Z.loader.hide('loader-target'));
        cy.get('.z-loader').should('not.exist');
    });

    it('shows the overlay while a preset request is running', () => {
        cy.visit('/Frontend/login');
        cy.intercept('POST', '**/login', {
            delay: 500,
            body: JSON.stringify({
                result: 'error',
                message: 'Username or password is wrong',
            }),
        }).as('login');

        cy.query('email').click().type('admin@zierhut-it.de');
        cy.query('password').click().type('password');
        cy.query('submit').click();

        cy.get('body > .z-loader').should('be.visible');
        cy.wait('@login');
        cy.get('.z-loader').should('not.exist');
    });

});
