import './commands'
import './database'
// Flake tracking: a test that only passed on a retry is a flake, not a pass.
// Record it so CI artifacts and local runs make flakiness visible over time.
afterEach(function() {
    const test = this.currentTest;
    if (test && test.state === 'passed' && test.currentRetry() > 0) {
        const line = `${new Date().toISOString()} ${Cypress.spec.relative} > ${test.fullTitle()} (passed on retry ${test.currentRetry()})\n`;
        cy.writeFile('flaky-tests.log', line, { flag: 'a+', log: false });
    }
});
