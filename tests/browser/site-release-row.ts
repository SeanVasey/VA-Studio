import { expect, type Locator, type Page } from '@playwright/test';

export const releaseRow = (page: Page, label: string) => page.getByRole('row').filter({ has: page.getByText(label, { exact: true }) });

export type ReleaseMenuAction = 'Edit as new draft' | 'Restore previous release' | 'Schedule publication';

/**
 * Secondary release actions live in each row's More menu. Opening it first means an absent action is really absent,
 * not merely hidden inside a closed menu.
 */
export async function openReleaseMenu(page: Page, label: string): Promise<Locator> {
  const row = releaseRow(page, label);
  await row.getByRole('button', { name: 'More', exact: true }).click();
  await expect(row.getByRole('button', { name: 'Edit as new draft', exact: true })).toBeVisible();
  return row;
}

export async function releaseMenuAction(page: Page, label: string, action: ReleaseMenuAction): Promise<Locator> {
  return (await openReleaseMenu(page, label)).getByRole('button', { name: action, exact: true });
}

/** The desktop admin fits every release action without scrolling the table sideways. */
export async function expectReleaseTableFits(page: Page) {
  const overflow = await page.evaluate(() => {
    const table = document.querySelector('.fi-ta-content-ctn, .fi-ta-content') as HTMLElement | null;
    return table === null ? null : table.scrollWidth - table.clientWidth;
  });
  expect(overflow).toBe(0);
}
