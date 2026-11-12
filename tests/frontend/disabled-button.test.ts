import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vitest';
import { setButtonDisabled } from '../../resources/js/admin/disabled-button';

function filamentButton(disabled: boolean) {
  const button = document.createElement('button');
  button.className = 'fi-btn fi-size-md' + (disabled ? ' fi-disabled' : '');
  if (disabled) { button.disabled = true; button.setAttribute('aria-disabled', 'true'); }
  return button;
}

describe('setButtonDisabled keeps the Filament disabled markers in step with the property', () => {
  it('clears aria-disabled and fi-disabled when a server-rendered disabled button is enabled', () => {
    const button = filamentButton(true);
    setButtonDisabled(button, false);
    expect(button.disabled).toBe(false);
    expect(button).not.toHaveAttribute('aria-disabled');
    expect(button).not.toHaveClass('fi-disabled');
  });

  it('sets all three markers again when disabling', () => {
    const button = filamentButton(false);
    setButtonDisabled(button, true);
    expect(button.disabled).toBe(true);
    expect(button).toHaveAttribute('aria-disabled', 'true');
    expect(button).toHaveClass('fi-disabled');
  });

  it('is used for every action button of both resumable upload pages', () => {
    for (const file of ['resumable-kit-upload', 'resumable-media-upload']) {
      const source = readFileSync(resolve(process.cwd(), `resources/js/admin/${file}.ts`), 'utf8');
      expect(source).toContain("from './disabled-button'");
      expect(source).not.toMatch(/action\([^)]*\)\.disabled\s*=/);
    }
  });
});
