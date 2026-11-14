// Run after catalog-pagination-seed.php, starting on its menu_url in an isolated browser session.
async (page) => {
  if (!/^https?:\/\/(localhost|127\.0\.0\.1)(:\d+)?\//.test(page.url())) throw new Error('Local QA site only.');
  const requests = [];
  page.on('response', response => requests.push({ url: response.url(), status: response.status() }));
  await page.context().clearCookies();
  await page.reload();
  await page.getByRole('button', { name: 'All 102', exact: true }).waitFor();
  if (await page.locator('article').count() !== 102) throw new Error('The menu is truncated at the first Store API page.');
  if (!requests.some(response => response.url.includes('/products') && response.url.includes('page=2') && response.status === 200)) throw new Error('Second Store API page was not loaded.');
  const addedResponse = page.waitForResponse(response => response.url().includes('/cart/add-item') && response.request().method() === 'POST');
  await page.getByRole('button', { name: 'Add ZZ Catalog pagination last dish', exact: true }).click();
  const response = await addedResponse;
  const cart = await response.json();
  const item = cart.items.find(item => item.sku === 'wr-catalog-pagination-000');
  if (response.status() !== 201 || !item || item.totals.line_total !== '275') throw new Error('Dish on the second catalog page could not be ordered.');
  return { rendered_products: 102, added_status: response.status(), added_sku: item.sku, line_total: item.totals.line_total };
}
