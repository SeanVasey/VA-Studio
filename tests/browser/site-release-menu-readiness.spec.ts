import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { openReleaseMenu, releaseMenuTrigger } from './site-release-row';

const dropdownView = readFileSync(new URL('../../resources/views/filament/admin/dropdown-focus.blade.php', import.meta.url), 'utf8');
const dropdownScript = dropdownView.match(/<script>([\s\S]*?)<\/script>/)?.[1];
if (!dropdownScript) throw new Error('The production dropdown accessibility script is missing.');

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

test('production dropdown ARIA follows replaced trigger and panel nodes and keeps the existing destroy lifecycle', async ({ page }) => {
  await page.setContent(`<div class="fi-dropdown" x-data="filamentDropdown">
    <div class="fi-dropdown-trigger"><button>More</button></div>
    <div class="fi-dropdown-panel" style="display: none"><button>Edit as new draft</button></div>
  </div>`);
  await page.evaluate(() => {
    const components = new WeakMap<Element, { $el: Element; panelId: string; observer: MutationObserver | null; syncAria: () => void; destroy: () => void }>();
    const install = (dropdown: Element, panelId: string) => {
      const originalPanel = dropdown.querySelector<HTMLElement>('.fi-dropdown-panel')!;
      const originalTrigger = dropdown.querySelector<HTMLButtonElement>('.fi-dropdown-trigger button')!;
      const component = {
        $el: dropdown, panelId, observer: null as MutationObserver | null,
        // Model the installed Filament lifecycle: init observes the original two nodes, even
        // though later sync calls read the current nodes. The production script replaces it.
        syncAria() {
          const panel = dropdown.querySelector<HTMLElement>('.fi-dropdown-panel')!;
          const trigger = dropdown.querySelector<HTMLButtonElement>('.fi-dropdown-trigger button')!;
          if (panel.id !== this.panelId) panel.id = this.panelId;
          if (trigger.getAttribute('aria-controls') !== this.panelId) trigger.setAttribute('aria-controls', this.panelId);
          const expanded = String(panel.style.display === 'block');
          if (trigger.getAttribute('aria-expanded') !== expanded) trigger.setAttribute('aria-expanded', expanded);
        },
        destroy() { this.observer?.disconnect(); this.observer = null; },
      };
      component.syncAria();
      component.observer = new MutationObserver(() => component.syncAria());
      component.observer.observe(originalPanel, { attributes: true, attributeFilter: ['style', 'id'] });
      component.observer.observe(originalTrigger, { attributes: true, attributeFilter: ['aria-controls', 'aria-expanded', 'aria-haspopup'] });
      components.set(dropdown, component);
      // Native activation delegates to the current panel after a morph; the ARIA layer must
      // follow the same current DOM without changing activation or synthesizing a user click.
      if (!dropdown.hasAttribute('data-fixture-activation')) {
        dropdown.setAttribute('data-fixture-activation', 'true');
        dropdown.addEventListener('click', event => {
          if (!(event.target instanceof Element) || !event.target.closest('.fi-dropdown-trigger') || event.target.closest('.fi-dropdown') !== dropdown) return;
          const panel = dropdown.querySelector<HTMLElement>('.fi-dropdown-panel')!;
          panel.style.display = panel.style.display === 'block' ? 'none' : 'block';
        });
      }
      return { dropdown, component, originalPanel, originalTrigger };
    };
    Object.assign(window, { Alpine: { $data: (element: Element | null): unknown => {
      // Actual Alpine falls back to an ancestor's data stack for an uninitialized child.
      while (element) {
        const component = components.get(element);
        if (component) return component;
        element = element.parentElement;
      }
      return {};
    } } });
    Object.defineProperty(window, '__dropdownAriaFixture', { value: { install, first: install(document.querySelector('.fi-dropdown')!, 'synthetic-stable-panel') } });
  });
  // Execute the actual rendered application source, not a second implementation in the test.
  await page.addScriptTag({ content: dropdownScript });
  await page.evaluate(() => document.dispatchEvent(new CustomEvent('alpine:initialized')));
  const dropdown = page.locator('.fi-dropdown').first();
  let trigger = dropdown.getByRole('button', { name: 'More', exact: true });
  await trigger.click();
  await expect(trigger).toHaveAttribute('aria-expanded', 'true');
  await page.evaluate(() => {
    const dropdown = document.querySelector('.fi-dropdown')!;
    dropdown.querySelector('.fi-dropdown-trigger')!.innerHTML = '<button>Replacement More</button>';
    dropdown.querySelector('.fi-dropdown-panel')!.outerHTML = '<div class="fi-dropdown-panel" style="display: none"><button>Edit as new draft</button></div>';
  });
  trigger = dropdown.getByRole('button', { name: 'Replacement More', exact: true });
  await expect(trigger).toHaveAttribute('aria-controls', 'synthetic-stable-panel');
  await expect(trigger).toHaveAttribute('aria-expanded', 'false');
  await trigger.click();
  await expect(dropdown.getByRole('button', { name: 'Edit as new draft', exact: true })).toBeVisible();
  await expect(trigger).toHaveAttribute('aria-expanded', 'true');
  // Changes to disconnected former nodes cannot corrupt the surviving component's ARIA.
  await page.evaluate(() => {
    const fixture = (window as unknown as { __dropdownAriaFixture: { first: { originalPanel: HTMLElement; originalTrigger: HTMLElement } } }).__dropdownAriaFixture;
    fixture.first.originalPanel.style.display = 'block';
    fixture.first.originalTrigger.setAttribute('aria-expanded', 'false');
  });
  await expect(trigger).toHaveAttribute('aria-expanded', 'true');
  await page.evaluate(() => {
    document.querySelector('.fi-dropdown-panel')!.removeAttribute('id');
    document.querySelector('.fi-dropdown-trigger button')!.removeAttribute('aria-expanded');
  });
  await expect(trigger).toHaveAttribute('aria-expanded', 'true');
  await expect(dropdown.locator(':scope > .fi-dropdown-panel')).toHaveAttribute('id', 'synthetic-stable-panel');
  await page.evaluate(() => document.querySelector('.fi-dropdown-panel')!.remove());
  await expect(trigger).toHaveAttribute('aria-expanded', 'false');
  await expect(trigger).not.toHaveAttribute('aria-controls');
  await page.evaluate(() => document.querySelector('.fi-dropdown')!.insertAdjacentHTML('beforeend', '<div class="fi-dropdown-panel" style="display: none"><button>Edit as new draft</button></div>'));
  await expect(trigger).toHaveAttribute('aria-controls', 'synthetic-stable-panel');
  await trigger.click();
  await expect(trigger).toHaveAttribute('aria-expanded', 'true');
  await page.evaluate(() => document.querySelector('.fi-dropdown-trigger button')!.remove());
  await page.evaluate(() => document.querySelector('.fi-dropdown-trigger')!.innerHTML = '<button>Replacement More</button>');
  trigger = dropdown.getByRole('button', { name: 'Replacement More', exact: true });
  await expect(trigger).toHaveAttribute('aria-expanded', 'true');
  await expect(trigger).toHaveAttribute('aria-controls', 'synthetic-stable-panel');
  // A nested root encountered before its own initialization inherits the outer Alpine scope.
  // Discovery must not steal the outer observer or give the inner panel the outer identity.
  await page.evaluate(() => {
    const fixture = (window as unknown as { __dropdownAriaFixture: { first: { component: { observer: MutationObserver | null } }; priorObserver?: MutationObserver | null } }).__dropdownAriaFixture;
    fixture.priorObserver = fixture.first.component.observer;
    document.querySelector('.fi-dropdown')!.insertAdjacentHTML('beforeend', '<div class="fi-dropdown" x-data="filamentDropdown" data-synthetic-nested><div class="fi-dropdown-trigger"><button>Nested More</button></div><div class="fi-dropdown-panel" style="display: none"><button>Nested action</button></div></div>');
  });
  expect(await page.evaluate(() => {
    const fixture = (window as unknown as { __dropdownAriaFixture: { first: { component: { observer: MutationObserver | null } }; priorObserver?: MutationObserver | null } }).__dropdownAriaFixture;
    return fixture.priorObserver === fixture.first.component.observer;
  })).toBe(true);
  await expect(trigger).toHaveAttribute('aria-expanded', 'true');
  await expect(page.locator('[data-synthetic-nested] > .fi-dropdown-panel')).not.toHaveAttribute('id');
  await page.evaluate(() => {
    const fixture = (window as unknown as { __dropdownAriaFixture: { install: (element: Element, id: string) => unknown } }).__dropdownAriaFixture;
    fixture.install(document.querySelector('[data-synthetic-nested]')!, 'synthetic-nested-panel');
  });
  const nestedTrigger = page.getByRole('button', { name: 'Nested More', exact: true });
  await nestedTrigger.click();
  await expect(nestedTrigger).toHaveAttribute('aria-expanded', 'true');
  await expect(nestedTrigger).toHaveAttribute('aria-controls', 'synthetic-nested-panel');
  await expect(trigger).toHaveAttribute('aria-expanded', 'true');
  // An independently initialized component added after Alpine startup is discovered as well.
  await page.evaluate(() => {
    const added = document.createElement('div');
    added.className = 'fi-dropdown'; added.setAttribute('x-data', 'filamentDropdown');
    added.setAttribute('data-synthetic-added', '');
    added.innerHTML = '<div class="fi-dropdown-trigger"><button>Added More</button></div><div class="fi-dropdown-panel" style="display: none"><button>Added action</button></div>';
    const fixture = (window as unknown as { __dropdownAriaFixture: { install: (element: Element, id: string) => unknown } }).__dropdownAriaFixture;
    fixture.install(added, 'synthetic-added-panel');
    document.body.append(added);
  });
  const addedTrigger = page.getByRole('button', { name: 'Added More', exact: true });
  await addedTrigger.click();
  await expect(addedTrigger).toHaveAttribute('aria-expanded', 'true');
  await expect(addedTrigger).toHaveAttribute('aria-controls', 'synthetic-added-panel');
  // HEAD discovery may run before Alpine's own insertion observer. Initializing later changes
  // only attributes; there is no second child-list mutation to accidentally make this pass.
  await page.evaluate(() => {
    const late = document.createElement('div');
    late.className = 'fi-dropdown'; late.setAttribute('x-data', 'filamentDropdown');
    late.setAttribute('data-synthetic-late', '');
    late.innerHTML = '<div class="fi-dropdown-trigger"><button>Late More</button></div><div class="fi-dropdown-panel" style="display: none"><button>Late action</button></div>';
    document.body.append(late);
  });
  await page.evaluate(() => {
    const fixture = (window as unknown as { __dropdownAriaFixture: { install: (element: Element, id: string) => unknown } }).__dropdownAriaFixture;
    fixture.install(document.querySelector('[data-synthetic-late]')!, 'synthetic-late-panel');
  });
  const lateTrigger = page.getByRole('button', { name: 'Late More', exact: true });
  await lateTrigger.click();
  await expect(lateTrigger).toHaveAttribute('aria-expanded', 'true');
  await expect(lateTrigger).toHaveAttribute('aria-controls', 'synthetic-late-panel');
  // An already initialized component may first be discovered between removal and reinsertion
  // of both nodes. Root observation must exist even when there is nothing to bind yet.
  await page.evaluate(() => {
    const gap = document.createElement('div');
    gap.className = 'fi-dropdown'; gap.setAttribute('x-data', 'filamentDropdown');
    gap.setAttribute('data-synthetic-gap', '');
    gap.innerHTML = '<div class="fi-dropdown-trigger"><button>Gap More</button></div><div class="fi-dropdown-panel" style="display: none"></div>';
    const fixture = (window as unknown as { __dropdownAriaFixture: { install: (element: Element, id: string) => unknown } }).__dropdownAriaFixture;
    fixture.install(gap, 'synthetic-gap-panel'); gap.innerHTML = '';
    document.body.append(gap);
  });
  await page.evaluate(() => {
    document.querySelector('[data-synthetic-gap]')!.innerHTML = '<div class="fi-dropdown-trigger"><button>Gap More</button></div><div class="fi-dropdown-panel" style="display: none"><button>Gap action</button></div>';
  });
  const gapTrigger = page.getByRole('button', { name: 'Gap More', exact: true });
  await expect(gapTrigger).toHaveAttribute('aria-controls', 'synthetic-gap-panel');
  await gapTrigger.click();
  await expect(gapTrigger).toHaveAttribute('aria-expanded', 'true');
  // Alpine may initialize a fresh component scope on the same surviving root. The former
  // binding cannot retain the old scope's panel identity or keep its observer attached.
  await page.evaluate(() => {
    const fixture = (window as unknown as { __dropdownAriaFixture: { install: (element: Element, id: string) => unknown } }).__dropdownAriaFixture;
    fixture.install(document.querySelector('[data-synthetic-added]')!, 'synthetic-renewed-panel');
  });
  await expect(addedTrigger).toHaveAttribute('aria-controls', 'synthetic-renewed-panel');
  await addedTrigger.click();
  await expect(addedTrigger).toHaveAttribute('aria-expanded', 'false');
  // The original destroy method disconnects the replacement observer. A removed component's
  // later attribute mutations must stay untouched, and discovery must not keep it alive.
  expect(await page.evaluate(async () => {
    const fixture = (window as unknown as { __dropdownAriaFixture: { first: { dropdown: Element; component: { observer: MutationObserver | null; destroy: () => void } } } }).__dropdownAriaFixture;
    const { dropdown, component } = fixture.first;
    const panel = dropdown.querySelector<HTMLElement>('.fi-dropdown-panel')!;
    const trigger = dropdown.querySelector<HTMLElement>('.fi-dropdown-trigger button')!;
    component.destroy(); dropdown.remove();
    panel.style.display = 'none'; trigger.setAttribute('aria-expanded', 'synthetic-destroyed');
    await new Promise<void>(resolve => queueMicrotask(resolve));
    return [component.observer === null, trigger.getAttribute('aria-expanded')];
  })).toEqual([true, 'synthetic-destroyed']);
});
