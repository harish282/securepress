// PressSentinel staging E2E — global hooks and custom commands.
require('./commands')

before(function () {
  const base = Cypress.config('baseUrl')
  if (!base || base === 'http://localhost') {
    Cypress.log({
      name: 'config',
      message:
        'Set CYPRESS_BASE_URL or copy cypress.env.example.json → cypress.env.json with your staging URL.',
    })
  }
})
