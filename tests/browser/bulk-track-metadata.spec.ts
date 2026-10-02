import { test, expect, type Locator, type Page } from '@playwright/test';
import { resetBrowserLoginRateLimit } from './auth-fixture';

test.beforeEach(() => resetBrowserLoginRateLimit());

type Metadata = {
  artist: string; bpm: string; musicalKey: string; genre: string; mood: string;
  tags: string[]; description: string;
};
type Draft = { title: string; slug: string; metadata: Metadata; id?: string };
type Field = 'artist' | 'bpm' | 'musicalKey' | 'genre' | 'mood';
type Choice = 'keep' | 'set' | 'clear';
type Choices = Record<Field, Choice>;

const fields: [Field, string][] = [
  ['artist', 'Artist'], ['bpm', 'BPM'], ['musicalKey', 'Musical key'], ['genre', 'Genre'], ['mood', 'Mood'],
];
const keepChoices: Choices = { artist: 'keep', bpm: 'keep', musicalKey: 'keep', genre: 'keep', mood: 'keep' };
const proposalHeading = 'Edit metadata for selected tracks';
const reviewHeading = 'Review metadata changes';
const trackRow = (page: Page, title: string) => page.getByRole('row').filter({ has: page.getByText(title, { exact: true }) });

function metadata(suffix: string): Metadata {
  return {
    artist: `SYNTHETIC BULK ARTIST ${suffix}`, bpm: suffix === 'B' ? '104' : '92', musicalKey: suffix === 'B' ? 'E minor' : 'D minor',
    genre: `Synthetic original genre ${suffix}`, mood: `Synthetic original mood ${suffix}`,
    tags: [`bulk-original-${suffix.toLowerCase()}`, 'ordered-second'],
    description: `Synthetic private bulk metadata draft ${suffix}; retained description.`,
  };
}

async function login(page: Page) {
  await page.goto('/admin/login');
  await page.getByLabel('Email address', { exact: false }).fill('browser-operator@example.test');
  await page.getByLabel('Password', { exact: false }).and(page.locator('input[type="password"]')).fill(process.env.VASEY_BROWSER_PASSWORD!);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await expect(page).toHaveURL(/\/admin$/);
}

async function searchTracks(page: Page, query: string) {
  // Selection must be checked after the actual debounced search reaches the server.
  const response = page.waitForResponse(response => {
    if (!response.url().endsWith('/update') || response.request().method() !== 'POST') return false;
    const payload = response.request().postDataJSON() as { components?: { updates?: Record<string, unknown> }[] };
    return payload.components?.some(component => component.updates?.tableSearch === query) ?? false;
  });
  await page.getByRole('searchbox', { name: 'Search', exact: true }).fill(query);
  const searched = await response;
  expect(searched.status()).toBe(200);
  expect(await searched.finished()).toBeNull();
  await expect(page.getByText(`Search: ${query}`, { exact: true })).toBeVisible();
}

async function findTracks(page: Page, prefix: string, drafts: Draft[]) {
  await page.goto('/admin/tracks');
  await searchTracks(page, prefix);
  for (const draft of drafts) await expect(trackRow(page, draft.title)).toBeVisible();
  await expect(page.getByRole('row').filter({ has: page.getByText(new RegExp(`^${prefix}`)) })).toHaveCount(drafts.length);
}

async function expectModalFits(page: Page, dialog: Locator) {
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
  const modalWindow = dialog.locator('.fi-modal-window');
  await expect(modalWindow).toHaveAttribute('tabindex', '-1');
  await expect(modalWindow).toHaveAttribute('autofocus', /.*/);
  expect(await modalWindow.evaluate(element => element.scrollWidth <= element.clientWidth + 1)).toBe(true);
  await expect.poll(() => dialog.evaluate(element => element.contains(document.activeElement))).toBe(true);
}

async function openDialog(page: Page, action: Locator, heading?: string) {
  await page.bringToFront();
  await expect(action).toBeEnabled();
  await action.focus();
  await action.press('Enter');
  const dialog = page.getByRole('dialog');
  await expect(heading === undefined ? dialog.getByRole('heading') : dialog.getByRole('heading', { name: heading, exact: true })).toBeVisible();
  await expectModalFits(page, dialog);
  return dialog;
}

async function cancelDialog(page: Page, dialog: Locator) {
  const response = page.waitForResponse(response => {
    if (!response.url().endsWith('/update') || response.request().method() !== 'POST') return false;
    const payload = response.request().postDataJSON() as { components?: { calls?: { method?: string }[] }[] };
    return payload.components?.some(component => component.calls?.some(call => call.method === 'unmountAction')) ?? false;
  });
  await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
  const closed = await response;
  expect(closed.status()).toBe(200);
  expect(await closed.finished()).toBeNull();
  await expect(dialog.getByRole('heading')).toBeHidden();
}

async function createDraft(page: Page, title: string, slug: string, data: Metadata): Promise<Draft> {
  const dialog = await openDialog(page, page.getByRole('button', { name: 'New track', exact: true }));
  await dialog.getByLabel('Title', { exact: false }).fill(title);
  await dialog.getByLabel('Slug', { exact: false }).fill(slug);
  await dialog.getByLabel('Artist', { exact: false }).fill(data.artist);
  await dialog.getByLabel('Bpm', { exact: false }).fill(data.bpm);
  await dialog.getByLabel('Musical key', { exact: false }).fill(data.musicalKey);
  await dialog.getByLabel('Genre', { exact: false }).fill(data.genre);
  await dialog.getByLabel('Mood', { exact: false }).fill(data.mood);
  const input = dialog.getByLabel('Tags', { exact: false }).and(dialog.locator('input[type="text"]'));
  for (const tag of data.tags) {
    await input.fill(tag);
    await input.press('Enter');
    await expect(dialog.locator('.fi-fo-tags-input-tags-ctn .fi-badge-label').filter({ hasText: tag })).toBeVisible();
  }
  await dialog.getByLabel('Description', { exact: false }).fill(data.description);
  await dialog.getByRole('button', { name: 'Create', exact: true }).click();
  await expect(dialog.getByRole('heading')).toBeHidden();
  await searchTracks(page, title);
  await expect(trackRow(page, title).getByText('draft', { exact: true })).toBeVisible();
  return { title, slug, metadata: data };
}

async function selectTracks(page: Page, drafts: Draft[]) {
  for (const draft of drafts) {
    const checkbox = trackRow(page, draft.title).getByRole('checkbox');
    const id = await checkbox.getAttribute('value');
    expect(id).toMatch(/^[1-9]\d*$/);
    draft.id = id!;
    await checkbox.check();
    await expect(checkbox).toBeChecked();
  }
}

async function openChoices(page: Page) {
  const dialog = await openDialog(page, page.getByRole('button', { name: 'Edit metadata', exact: true }), proposalHeading);
  for (const [, label] of fields) {
    await expect(dialog.getByLabel(`${label} change`, { exact: false })).toHaveValue('keep');
    await expect(dialog.getByLabel(`${label} value`, { exact: false })).toBeHidden();
  }
  // Artist is required; the normal control must not offer clearing it.
  await expect(dialog.getByLabel('Artist change', { exact: false }).locator('option[value="clear"]')).toHaveCount(0);
  return dialog;
}

async function choose(dialog: Locator, field: Field, choice: Choice, value?: string) {
  const label = fields.find(([key]) => key === field)![1];
  await dialog.getByLabel(`${label} change`, { exact: false }).selectOption(choice);
  const input = dialog.getByLabel(`${label} value`, { exact: false });
  if (choice === 'set') {
    await expect(input).toBeVisible();
    await input.fill(value!);
  } else {
    await expect(input).toBeHidden();
  }
}

async function review(page: Page, drafts: Draft[], before: Metadata[], after: Metadata[], choices: Choices, changed: number) {
  const dialog = page.getByRole('dialog');
  await dialog.getByRole('button', { name: 'Review metadata changes', exact: true }).click();
  await expect(dialog.getByRole('heading', { name: reviewHeading, exact: true })).toBeVisible();
  await expect(dialog.getByText(`${changed} tracks will change; ${drafts.length - changed} already match these choices.`, { exact: true })).toBeVisible();
  await expect(dialog.getByRole('group')).toHaveCount(drafts.length);
  for (const [index, draft] of drafts.entries()) {
    const group = dialog.getByRole('group', { name: draft.title, exact: true });
    await expect(group).toBeVisible();
    await expect(group).toContainText(new RegExp(`Track ID:\\s*${draft.id}\\b`));
    const table = group.getByRole('table');
    await expect(table.getByRole('columnheader')).toHaveText(['Field', 'Choice', 'Current metadata', 'Proposed metadata']);
    for (const [field, label] of fields) {
      const row = table.getByRole('row').filter({ has: table.getByText(label, { exact: true }) });
      const choice = choices[field][0].toUpperCase() + choices[field].slice(1);
      await expect(row.locator('th, td')).toHaveText([label, choice, before[index][field] || 'No value', after[index][field] || 'No value']);
    }
  }
  await expect(dialog.getByRole('button', { name: 'Save reviewed metadata', exact: true })).toBeEnabled();
  await expectModalFits(page, dialog);
  return dialog;
}

async function saveReview(page: Page, dialog: Locator, message: string) {
  const save = dialog.getByRole('button', { name: 'Save reviewed metadata', exact: true });
  await save.focus();
  await save.press('Enter');
  await expect(dialog.getByRole('heading', { name: reviewHeading, exact: true })).toBeHidden();
  await expect(page.getByText(message, { exact: true })).toBeVisible();
}

async function expectSaved(page: Page, draft: Draft, data: Metadata) {
  await page.goto('/admin/tracks');
  await searchTracks(page, draft.title);
  await expect(trackRow(page, draft.title).getByText('draft', { exact: true })).toBeVisible();
  const dialog = await openDialog(page, trackRow(page, draft.title).getByRole('button', { name: 'Edit', exact: true }));
  await expect(dialog.getByLabel('Title', { exact: false })).toHaveValue(draft.title);
  await expect(dialog.getByLabel('Slug', { exact: false })).toHaveValue(draft.slug);
  await expect(dialog.getByLabel('Artist', { exact: false })).toHaveValue(data.artist);
  await expect(dialog.getByLabel('Bpm', { exact: false })).toHaveValue(data.bpm);
  await expect(dialog.getByLabel('Musical key', { exact: false })).toHaveValue(data.musicalKey);
  await expect(dialog.getByLabel('Genre', { exact: false })).toHaveValue(data.genre);
  await expect(dialog.getByLabel('Mood', { exact: false })).toHaveValue(data.mood);
  await expect(dialog.locator('.fi-fo-tags-input-tags-ctn .fi-badge-label')).toHaveText(data.tags);
  await expect(dialog.getByLabel('Description', { exact: false })).toHaveValue(data.description);
  await cancelDialog(page, dialog);
}

test('bulk metadata choices review exact rows, persist Set and Clear, preserve Keep and recover from errors and cancellation', async ({ page, playwright }, testInfo) => {
  test.setTimeout(150_000);
  const failures: string[] = [];
  page.on('pageerror', error => failures.push(error.message));
  await login(page);
  await page.goto('/admin/tracks');
  const prefix = `Synthetic bulk metadata choices ${testInfo.project.name}`;
  const drafts = [
    await createDraft(page, `${prefix} A`, `bulk-metadata-choices-${testInfo.project.name}-a`, metadata('A')),
    await createDraft(page, `${prefix} B`, `bulk-metadata-choices-${testInfo.project.name}-b`, metadata('B')),
    await createDraft(page, `${prefix} Control`, `bulk-metadata-choices-${testInfo.project.name}-control`, metadata('Control')),
  ];
  const selected = drafts.slice(0, 2);
  const before = selected.map(draft => draft.metadata);
  await findTracks(page, prefix, drafts);
  await selectTracks(page, selected);
  await expect(trackRow(page, drafts[2].title).getByRole('checkbox')).not.toBeChecked();
  let dialog = await openChoices(page);
  await choose(dialog, 'artist', 'set', 'SYNTHETIC REVIEWED BULK ARTIST');
  await choose(dialog, 'bpm', 'set', '110');
  await choose(dialog, 'musicalKey', 'clear');
  await choose(dialog, 'mood', 'set', 'Synthetic temporary reviewed mood');
  const initialChoices: Choices = { artist: 'set', bpm: 'set', musicalKey: 'clear', genre: 'keep', mood: 'set' };
  const initialAfter = before.map(data => ({ ...data, artist: 'SYNTHETIC REVIEWED BULK ARTIST', bpm: '110', musicalKey: '', mood: 'Synthetic temporary reviewed mood' }));
  dialog = await review(page, selected, before, initialAfter, initialChoices, 2);
  await expect(dialog.getByRole('group', { name: drafts[2].title, exact: true })).toHaveCount(0);

  await dialog.getByRole('button', { name: 'Back to metadata choices', exact: true }).click();
  await expect(dialog.getByRole('heading', { name: proposalHeading, exact: true })).toBeVisible();
  await expect(dialog.getByRole('button', { name: 'Save reviewed metadata', exact: true })).toHaveCount(0);
  await expect(dialog.getByLabel('Artist value', { exact: false })).toHaveValue(initialAfter[0].artist);
  await expect(dialog.getByLabel('Mood value', { exact: false })).toHaveValue(initialAfter[0].mood);
  await choose(dialog, 'mood', 'clear');
  const choices: Choices = { ...initialChoices, mood: 'clear' };
  const saved = initialAfter.map(data => ({ ...data, mood: '' }));
  dialog = await review(page, selected, before, saved, choices, 2);
  await page.screenshot({ path: testInfo.outputPath('bulk-metadata-review.png'), fullPage: false });
  await saveReview(page, dialog, 'Metadata saved for 2 tracks. 0 tracks already matched these choices.');
  for (const draft of selected) await expect(trackRow(page, draft.title).getByRole('checkbox')).not.toBeChecked();
  for (const [index, draft] of selected.entries()) await expectSaved(page, draft, saved[index]);
  await expectSaved(page, drafts[2], drafts[2].metadata);

  await findTracks(page, prefix, drafts);
  await selectTracks(page, selected);
  dialog = await openChoices(page);
  // Nonempty whitespace reaches server validation instead of a native required-input bubble.
  await choose(dialog, 'artist', 'set', '   ');
  await dialog.getByRole('button', { name: 'Review metadata changes', exact: true }).click();
  const error = dialog.locator('.fi-fo-field-wrp-error-message').filter({ hasText: /artist.*required/i });
  await expect(error).toHaveCount(1);
  await expect(error).toBeVisible();
  await expect(dialog.getByLabel('Artist value', { exact: false })).toHaveValue('   ');
  await expect(dialog.getByRole('heading', { name: proposalHeading, exact: true })).toBeVisible();
  await expect(dialog.getByRole('button', { name: 'Save reviewed metadata', exact: true })).toHaveCount(0);
  await error.scrollIntoViewIfNeeded();
  await expect(error).toBeInViewport();
  await expectModalFits(page, dialog);
  await page.screenshot({ path: testInfo.outputPath('bulk-metadata-validation.png'), fullPage: false });
  await dialog.getByLabel('Artist value', { exact: false }).focus();
  await expect(dialog.getByLabel('Artist value', { exact: false })).toBeFocused();
  await dialog.getByLabel('Artist value', { exact: false }).fill(saved[0].artist);
  dialog = await review(page, selected, saved, saved, { ...keepChoices, artist: 'set' }, 0);
  await saveReview(page, dialog, 'No metadata changed. All 2 reviewed tracks already matched these choices.');

  // Cancel an actual review; navigating back must not retain an apply action or mutate either target.
  await findTracks(page, prefix, drafts);
  await selectTracks(page, selected);
  dialog = await openChoices(page);
  await choose(dialog, 'genre', 'set', 'Synthetic cancelled genre');
  dialog = await review(page, selected, saved, saved.map(data => ({ ...data, genre: 'Synthetic cancelled genre' })), { ...keepChoices, genre: 'set' }, 2);
  await cancelDialog(page, dialog);
  for (const [index, draft] of selected.entries()) await expectSaved(page, draft, saved[index]);
  await expectSaved(page, drafts[2], drafts[2].metadata);

  // A normal search filter clears current selection; the next review contains only the newly checked visible row.
  await findTracks(page, prefix, drafts);
  await selectTracks(page, selected);
  await searchTracks(page, selected[0].title);
  await expect(trackRow(page, selected[1].title)).toHaveCount(0);
  await expect(trackRow(page, selected[0].title).getByRole('checkbox')).not.toBeChecked();
  await selectTracks(page, [selected[0]]);
  dialog = await openChoices(page);
  await choose(dialog, 'genre', 'set', 'Synthetic filtered proposal');
  dialog = await review(page, [selected[0]], [saved[0]], [{ ...saved[0], genre: 'Synthetic filtered proposal' }], { ...keepChoices, genre: 'set' }, 1);
  await expect(dialog.getByRole('group', { name: selected[1].title, exact: true })).toHaveCount(0);
  await cancelDialog(page, dialog);

  const visitor = await playwright.request.newContext({ baseURL: 'http://127.0.0.1:8173' });
  try {
    for (const draft of drafts) expect((await visitor.get(`/tracks/${draft.slug}`)).status()).toBe(404);
    const catalog = await visitor.get('/api/catalog');
    expect(catalog.status()).toBe(200);
    const content = await catalog.text();
    for (const draft of drafts) {
      expect(content).not.toContain(draft.slug);
      expect(content).not.toContain(draft.title);
    }
    expect(failures).toEqual([]);
  } finally {
    await visitor.dispose();
  }
});

test('a competing normal metadata edit rejects the entire reviewed batch and recovery shows the winning values', async ({ page, browser }, testInfo) => {
  test.setTimeout(120_000);
  const failures: string[] = [];
  const watch = (target: Page) => target.on('pageerror', error => failures.push(error.message));
  watch(page);
  await login(page);
  await page.goto('/admin/tracks');
  const prefix = `Synthetic bulk metadata conflict ${testInfo.project.name}`;
  const drafts = [
    await createDraft(page, `${prefix} A`, `bulk-metadata-conflict-${testInfo.project.name}-a`, metadata('A')),
    await createDraft(page, `${prefix} B`, `bulk-metadata-conflict-${testInfo.project.name}-b`, metadata('B')),
  ];
  await findTracks(page, prefix, drafts);
  await selectTracks(page, drafts);
  expect(Number(drafts[0].id)).toBeLessThan(Number(drafts[1].id));
  let dialog = await openChoices(page);
  const choices: Choices = { ...keepChoices, genre: 'set' };
  const before = drafts.map(draft => draft.metadata);
  const genre = 'Synthetic stale batch genre';
  await choose(dialog, 'genre', 'set', genre);
  dialog = await review(page, drafts, before, before.map(data => ({ ...data, genre })), choices, 2);

  const otherContext = await browser.newContext({ baseURL: 'http://127.0.0.1:8173', viewport: page.viewportSize() });
  try {
    const other = await otherContext.newPage();
    watch(other);
    await login(other);
    await findTracks(other, prefix, drafts);
    const otherDialog = await openDialog(other, trackRow(other, drafts[1].title).getByRole('button', { name: 'Edit', exact: true }));
    const winner = { ...before[1], mood: 'Synthetic winning competing mood' };
    await otherDialog.getByLabel('Mood', { exact: false }).fill(winner.mood);
    await otherDialog.getByRole('button', { name: 'Save changes', exact: true }).click();
    await expect(otherDialog.getByRole('heading')).toBeHidden();

    // The later-ID target changes a Keep field. Even the earlier eligible target must remain untouched.
    await page.bringToFront();
    await dialog.getByRole('button', { name: 'Save reviewed metadata', exact: true }).click();
    await expect(page.getByText('No changes were saved by this attempt.', { exact: true })).toBeVisible();
    await expect(page.getByText('A selected track changed after review. Review the current tracks before trying again.', { exact: true })).toBeVisible();
    await expect(dialog.getByRole('heading', { name: proposalHeading, exact: true })).toBeVisible();
    await expect(dialog.getByRole('button', { name: 'Save reviewed metadata', exact: true })).toHaveCount(0);
    await expect(dialog.getByLabel('Genre value', { exact: false })).toHaveValue(genre);
    await expectModalFits(page, dialog);
    await page.screenshot({ path: testInfo.outputPath('bulk-metadata-stale-review.png'), fullPage: false });
    const current = [before[0], winner];
    for (const [index, draft] of drafts.entries()) await expectSaved(other, draft, current[index]);

    // Recovery reads both current rows and visibly includes the competing winner before this new save.
    await page.bringToFront();
    const saved = current.map(data => ({ ...data, genre }));
    dialog = await review(page, drafts, current, saved, choices, 2);
    await saveReview(page, dialog, 'Metadata saved for 2 tracks. 0 tracks already matched these choices.');
    for (const [index, draft] of drafts.entries()) {
      await expectSaved(page, draft, saved[index]);
      expect((await page.request.get(`/tracks/${draft.slug}`)).status()).toBe(404);
    }
    expect(failures).toEqual([]);
  } finally {
    await otherContext.close();
  }
});
