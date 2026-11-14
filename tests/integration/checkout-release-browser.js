// Final packaged-plugin smoke test on the disposable site seeded by seed-browser.php.
async (page) => {
  const assert = (ok, message) => { if (!ok) throw new Error(message); };
  await page.context().clearCookies();
  await page.goto('http://127.0.0.1:18889/qa-menu/', { waitUntil: 'domcontentloaded' });
  await page.evaluate(() => sessionStorage.clear());
  await page.getByRole('button', { name: 'Pickup', exact: true }).click();
  await page.getByRole('button', { name: 'Customise QA Decimal Pizza', exact: true }).click();
  const dialog = page.getByRole('dialog');
  await dialog.getByRole('button', { name: /^Add ·/ }).click();
  await dialog.getByRole('alert').getByText('Choose one option to add this item.').waitFor();
  await dialog.getByRole('radio', { name: /Extra cheese/ }).check();
  await dialog.getByRole('button', { name: 'Add · £13.75', exact: true }).click();
  await page.getByRole('button', { name: 'Checkout · £13.75', exact: true }).click();
  for (const [name, value] of Object.entries({ 'Email address': 'qa-release@example.test', 'Full name': 'QA Release', Phone: '+442079460123' })) {
    await page.getByRole('textbox', { name, exact: true }).fill(value);
  }
  await page.getByRole('button', { name: 'Cash on delivery Pay on collection or delivery.' }).click();
  const placed = page.waitForResponse(r => r.url().includes('/wc/store/v1/checkout') && r.request().method() === 'POST');
  await page.getByRole('button', { name: 'Place order · £13.75', exact: true }).click();
  assert((await placed).status() === 200, 'Packaged plugin checkout failed.');
  await page.waitForURL(/confirmation|order-received/);
  const receipt = await page.locator('main').innerText();
  assert(receipt.includes('£13.75') && receipt.includes('Extra cheese (+£1.25)') && receipt.includes('kitchen@example.test'), 'Receipt lost totals, add-on or restaurant contact.');
  const cart = await page.evaluate(async () => (await fetch('/wp-json/wc/store/v1/cart')).json());
  assert(cart.items.length === 0, 'Paid checkout did not clear the cart.');
  await page.reload({ waitUntil: 'domcontentloaded' });
  assert((await page.locator('main').innerText()).includes('Extra cheese (+£1.25)'), 'Receipt does not survive reload.');
  await page.screenshot({ path: 'output/playwright/release-checkout-confirmation.png', fullPage: true });
  return { order: receipt.match(/ORDER #(\d+)/)?.[1], cart_empty: true, receipt };
}
