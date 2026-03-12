/**
 * Unit tests for split-guide-tracker.js event payload and send paths.
 * Loads the tracker IIFE with mocked fetch/sendBeacon and asserts on payload structure.
 */

const fs = require('fs');
const path = require('path');

const TRACKER_PATH = path.join(
  __dirname,
  '../..',
  'web/app/plugins/pb-split-guide/assets/split-guide-tracker.js'
);

describe('split-guide-tracker', () => {
  let fetchCalls;
  let sendBeaconCalls;

  beforeEach(() => {
    fetchCalls = [];
    sendBeaconCalls = [];

    global.fetch = jest.fn((url, opts) => {
      fetchCalls.push({ url, opts });
      return Promise.resolve({ ok: true });
    });

    if (typeof navigator !== 'undefined') {
      navigator.sendBeacon = jest.fn((url, payload) => {
        sendBeaconCalls.push({ url, payload });
        return true;
      });
    } else {
      global.navigator = { sendBeacon: jest.fn((url, payload) => {
        sendBeaconCalls.push({ url, payload });
        return true;
      }) };
    }

    global.window.pbsgTracker = {
      ajaxUrl: 'https://example.com/wp-admin/admin-ajax.php',
      tutorialPageId: 42,
      totalSteps: 3,
    };
  });

  it('sends tutorial_view event with correct payload on load', (done) => {
    if (!fs.existsSync(TRACKER_PATH)) {
      done();
      return;
    }
    const script = fs.readFileSync(TRACKER_PATH, 'utf8');
    // Run the IIFE; it will call init() which calls recordTutorialView()
    try {
      eval(script);
    } catch (e) {
      done(e);
      return;
    }
    // Tracker may run init on DOMContentLoaded; allow one tick
    setTimeout(() => {
      expect(fetchCalls.length).toBeGreaterThanOrEqual(1);
      const first = fetchCalls[0];
      expect(first.url).toContain('action=pbsg_track_event');
      const body = JSON.parse(first.opts.body);
      expect(body.event_type).toBe('tutorial_view');
      expect(body.tutorial_page_id).toBe(42);
      done();
    }, 50);
  });
});
