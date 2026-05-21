/**
 * STAGING_TEST_PLAN.md — §7 Global rate limiting
 */
describe('Global rate limiting', () => {
  const enabled = 'presssentinel_rate_limit[enabled]'
  const limit = 'presssentinel_rate_limit[limit]'
  const window = 'presssentinel_rate_limit[window]'
  const restPath = '/wp-json/wp/v2/posts?per_page=1'

  beforeEach(() => {
    cy.wpLogin()
  })

  after(() => {
    cy.wpLogin()
    cy.setDashboardFeature('rate_limit', false)
    cy.visitPressSentinel('presssentinel-rate-limit')
    cy.get(`input[name="${enabled}"]`).uncheck({ force: true })
    cy.saveWpOptionsForm()
  })

  it('returns 429 on REST after burst when limit is low', () => {
    cy.setDashboardFeature('rate_limit', true)
    cy.visitPressSentinel('presssentinel-rate-limit')
    cy.get(`input[name="${enabled}"]`).check({ force: true })
    cy.get(`input[name="${limit}"]`).clear().type('10')
    cy.get(`input[name="${window}"]`).clear().type('60')
    cy.saveWpOptionsForm()

    cy.burstRest(restPath, 15).then((codes) => {
      expect(codes.filter((c) => c === 429).length, '429 responses').to.be.gte(1)
    })

    cy.visitPressSentinel('presssentinel')
    cy.contains('h1', 'Press Sentinel').should('be.visible')
  })

  it('stops throttling when rate limiting is disabled', () => {
    cy.setDashboardFeature('rate_limit', false)
    cy.burstRest(restPath, 5).then((codes) => {
      expect(codes.every((c) => c !== 429), 'no 429 when disabled').to.be.true
    })
  })
})
