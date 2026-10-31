'use strict';

const { beginHttpObservation } = require('../observability');

function registerFastifyObservability(app) {
  app.addHook('onRequest', (request, reply, done) => {
    const observation = beginHttpObservation(request.raw, reply.raw, {
      route: () => request.routeOptions?.url || 'unmatched',
    });
    observation.run(done);
  });
}

module.exports = { registerFastifyObservability };
