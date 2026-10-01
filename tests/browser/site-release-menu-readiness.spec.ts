import { test, expect } from '@playwright/test';
import { openReleaseMenu, releaseMenuTrigger } from './site-release-row';

test('release menu opening waits for replaced-panel identity and visibility to agree with ARIA', async ({ page }) => {
  // Reproduce the recorded Livewire morph boundary: the new closed panel has no id, while
  // the surviving trigger still advertises the old open panel. This fixture tests the helper;
  // site-schedule.spec.ts continues to cover the real Filament submission and native menu.
  const label = 'Synthetic replaced panel';
  await page.setContent(`<table><tbody><tr><td>${label}</td><td><div class="fi-dropdown">
    <div class="fi-dropdown-trigger"><button aria-label="More actions for “${label}”" aria-controls="old-panel" aria-expanded="true">More</button></div>
    <div class="fi-dropdown-panel" style="display: none"><button>Edit as new draft</button></div>
    </div></td></tr></tbody></table>`);
  await page.evaluate(() => {
    const trigger = document.querySelector<HTMLButtonElement>('.fi-dropdown-trigger button')!;
    const panel = document.querySelector<HTMLElement>('.fi-dropdown-panel')!;
    document.body.dataset.clicks = '0';
    document.body.dataset.earlyClicks = '0';
    trigger.addEventListener('click', () => {
      document.body.dataset.clicks = String(Number(document.body.dataset.clicks) + 1);
      if (trigger.getAttribute('aria-controls') !== panel.id || !panel.id || trigger.getAttribute('aria-expanded') !== String(panel.style.display !== 'none')) document.body.dataset.earlyClicks = '1';
      const open = panel.style.display === 'none';
      panel.style.display = open ? 'block' : 'none';
      trigger.setAttribute('aria-expanded', String(open));
    });
    // Controlled fixture latency, rather than a sleep in the production test helper.
    setTimeout(() => { panel.id = 'replacement-panel'; trigger.setAttribute('aria-controls', panel.id); }, 150);
    setTimeout(() => { trigger.setAttribute('aria-expanded', 'false'); }, 300);
  });
  const row = await openReleaseMenu(page, label);
  await expect(row.getByRole('button', { name: 'Edit as new draft', exact: true })).toBeVisible();
  await expect(releaseMenuTrigger(page, label)).toHaveAttribute('aria-controls', 'replacement-panel');
  await expect(page.locator('body')).toHaveAttribute('data-early-clicks', '0');
  await expect(page.locator('body')).toHaveAttribute('data-clicks', '1');
  // A second call must preserve a menu that is already open, rather than toggle it closed.
  await openReleaseMenu(page, label);
  await expect(page.locator('body')).toHaveAttribute('data-clicks', '1');
});
