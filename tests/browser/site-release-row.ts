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
  const trigger = releaseMenuTrigger(page, label);
  // Clicking a menu that is already open would close it.
  if (await trigger.getAttribute('aria-expanded') !== 'true') await trigger.click();
  const row = releaseRow(page, label);
  await expect(row.getByRole('button', { name: 'Edit as new draft', exact: true })).toBeVisible();
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
