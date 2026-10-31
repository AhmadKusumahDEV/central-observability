'use strict';

const { beginHttpObservation } = require('../observability');

function routeFromExpress(req) {
  const path = req.route?.path;
  if (!path) return 'unmatched';
  const template = Array.isArray(path) ? path[0] : path;
  return `${req.baseUrl || ''}${template}`;
}

function expressObservability() {
  return (req, res, next) => {
    const observation = beginHttpObservation(req, res, { route: () => routeFromExpress(req) });
    observation.run(next);
  };
}

module.exports = { expressObservability, routeFromExpress };
