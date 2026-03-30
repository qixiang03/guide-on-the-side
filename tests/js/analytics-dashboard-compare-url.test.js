/**
 * Mirrors compare-view id parsing in analytics-dashboard.js init().
 * If dashboard URL handling changes, update this test to match.
 */
function parseCompareIdsFromSearch(search) {
  const urlParams = new URLSearchParams(search);
  const idsParam = urlParams.get('ids') || '';
  return idsParam ? idsParam.split(',').map(Number).filter(Boolean) : [];
}

describe('analytics-dashboard compare URL ids', () => {
  test('empty search yields empty list', () => {
    expect(parseCompareIdsFromSearch('')).toEqual([]);
  });

  test('parses comma-separated ids and drops zero', () => {
    expect(parseCompareIdsFromSearch('?ids=3,0,5')).toEqual([3, 5]);
  });

  test('handles leading question mark omitted', () => {
    expect(parseCompareIdsFromSearch('ids=10&x=1')).toEqual([10]);
  });
});
