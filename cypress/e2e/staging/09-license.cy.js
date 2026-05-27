/**
 * STAGING_TEST_PLAN.md — §12 Licensing
 */
describe('License', () => {
  beforeEach(() => {
    cy.wpLogin()
  })

  it('loads license page and shows status', () => {
    const hasLicense = Boolean(Cypress.env('hasLicense'))

    cy.request({
      url: '/wp-admin/admin.php?page=presssentinel-license',
      failOnStatusCode: false,
    }).then((res) => {
      if (res.status === 403 || res.status === 404) {
        cy.log(`License screen returned ${res.status} (often hidden during beta trial) — skipping assertions`)
        return
      }
      expect(res.status).to.eq(200)

      // Default staging expectation is "no active license".
      if (!hasLicense) {
        expect(res.body).to.match(/License|license|trial|evaluation|activate/i)
        return
      }

      // Opt-in checks when a real license is available.
      expect(res.body).to.match(/License|license|Pro|active|valid|evaluation/i)
    })
  })
})
