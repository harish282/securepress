/**
 * STAGING_TEST_PLAN.md — §7 Global rate limiting
 */
describe('Global rate limiting', () => {
  const enabled = 'niyiguard_rate_limit[enabled]'
  const limit = 'niyiguard_rate_limit[limit]'
  const window = 'niyiguard_rate_limit[window]'
  // Use rest_route form so the test works even when Apache/Nginx rewrites are disabled.
  const restPath = '/?rest_route=/wp/v2/posts&per_page=1'

  beforeEach(() => {
    cy.wpLogin()
  })

  after(() => {
    cy.wpLogin()
    cy.setDashboardFeature('rate_limit', false)
    cy.visitNiyiGuard('niyiguard-rate-limit')
    cy.get('body').then(($body) => {
      if ($body.find(`input[type="checkbox"][name="${enabled}"]`).length) {
        cy.uncheckWpSetting(enabled)
        cy.saveWpOptionsForm()
      }
    })
  })

  it('returns 429 on REST after burst when limit is low', () => {
    cy.setDashboardFeature('rate_limit', true)
    cy.visitNiyiGuard('niyiguard-rate-limit')
    cy.checkWpSetting(enabled)
    cy.get(`input[name="${limit}"]`).clear().type('10')
    cy.get(`input[name="${window}"]`).clear().type('60')
    cy.saveWpOptionsForm()

    cy.burstRest(restPath, 25).then((codes) => {
      const throttled = codes.filter((c) => c === 429).length
      expect(throttled, `expected 429s, got statuses: ${codes.join(',')}`).to.be.gte(1)
    })

    cy.visitNiyiGuard('niyiguard')
    cy.contains('h1', 'NiyiGuard').should('be.visible')
  })

  it('stops throttling when rate limiting is disabled', () => {
    cy.setDashboardFeature('rate_limit', false)
    cy.burstRest(restPath, 5).then((codes) => {
      expect(codes.every((c) => c !== 429), 'no 429 when disabled').to.be.true
    })
  })
})
