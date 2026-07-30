describe('Model', () => {
    before(() => {
        cy.dbSeed();
    });

    it('Controller Model', () => {
        cy.visit("/Core/Model");
        cy.contains("Test Model Call");
    });

    it('Model Select Line', () => {
        cy.visit("/Core/modelselectline");
        cy.contains("ABC");
        cy.contains("CDE").should("not.exist");
    });

    it('Model Select Array', () => {
        cy.visit("/Core/modelselectarray");
        cy.contains("ABC");
        cy.contains("CDE");
    });

    it('Model Insert', () => {
        cy.visit("/Core/modelinsert");
        cy.contains("TestData");
    });

    it('Model Last Id', () => {
        // The probe compares getInsertId() against the persisted row itself;
        // the exact value is cluster-offset dependent and not the contract.
        cy.visit("/Core/modellastid");
        cy.contains("lastid-consistent");
    });

    it('Model Count', () => {
        cy.visit("/Core/modelcount");
        cy.contains("31");
    });

});