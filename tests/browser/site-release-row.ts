import { expect, type Locator, type Page } from '@playwright/test';

export const releaseRow = (page: Page, label: string) => page.getByRole('row').filter({ has: page.getByText(label, { exact: true }) });

export type ReleaseMenuAction = 'Edit as new draft' | 'Restore previous release' | 'Schedule publication';

/** Each row's More button names its release, so a screen reader's list of buttons tells the rows apart. */
export const releaseMenuTrigger = (page: Page, label: string) =>
  releaseRow(page, label).getByRole('button', { name: `More actions for “${label}”`, exact: true });

/**
 * Secondary release actions live in each row's More menu. Opening it first means an absent action is really absent,
 * not merely hidden inside a closed menu.
 */
export async function openReleaseMenu(page: Page, label: string): Promise<Locator> {
  const row = releaseRow(page, label);
  // Releases are retained and the table pages newest first. A previous release can therefore
  // be off the first page; use the real searchable label rather than assuming every row is rendered.
  if (await row.count() === 0) {
    const search = page.getByRole('searchbox', { name: 'Search', exact: true });
    // Filling an unchanged query need not send another update; wait for its existing result instead.
    if (await search.inputValue() !== label) {
      const response = page.waitForResponse(response => {
        if (!response.url().endsWith('/update') || response.request().method() !== 'POST') return false;
        const payload = response.request().postDataJSON() as { components?: { updates?: Record<string, unknown> }[] };
        return payload.components?.some(component => component.updates?.tableSearch === label) ?? false;
      });
      await search.fill(label);
      const committed = await response;
      expect(committed.status()).toBe(200);
      expect(await committed.finished()).toBeNull();
    }
    await expect(page.getByText(`Search: ${label}`, { exact: true })).toBeVisible();
  }
  await expect(row).toHaveCount(1);
  const trigger = releaseMenuTrigger(page, label);
  await expect(trigger).toHaveCount(1);
  // A Livewire morph can replace a closed panel before Alpine repairs the surviving trigger's
  // old id/expanded attributes. Wait for those public DOM states to agree before deciding to toggle.
  await expect.poll(() => trigger.evaluate(element => {
    const panel = element.closest('.fi-dropdown')?.querySelector<HTMLElement>(':scope > .fi-dropdown-panel');
    if (!panel?.id || element.getAttribute('aria-controls') !== panel.id) return false;
    const visible = panel.getClientRects().length > 0 && getComputedStyle(panel).visibility !== 'hidden';
    return element.getAttribute('aria-expanded') === String(visible);
  })).toBe(true);
  // Clicking a menu that is already open would close it.
  if (await trigger.getAttribute('aria-expanded') !== 'true') await trigger.click();
  await expect(row.getByRole('button', { name: 'Edit as new draft', exact: true })).toBeVisible();
  await expect(trigger).toHaveAttribute('aria-expanded', 'true');
  return row;
}

export async function releaseMenuAction(page: Page, label: string, action: ReleaseMenuAction): Promise<Locator> {
  return (await openReleaseMenu(page, label)).getByRole('button', { name: action, exact: true });
}

/** The desktop admin fits every release action: neither the table nor the page scrolls sideways. */
export async function expectReleaseTableFits(page: Page) {
  await expect.poll(() => page.evaluate(() => {
    const table = document.querySelector('.fi-ta-content-ctn') as HTMLElement | null;
    const root = document.documentElement;
    return table === null ? null : [table.scrollWidth - table.clientWidth, root.scrollWidth - root.clientWidth];
  })).toEqual([0, 0]);
}
