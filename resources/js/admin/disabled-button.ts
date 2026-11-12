/**
 * Filament 5.11 renders `<x-filament::button disabled>` with aria-disabled="true" and the fi-disabled class, and its
 * window click listener swallows every click on a `.fi-btn[aria-disabled="true"]`. A script that only flips the
 * `disabled` property would leave a button it enabled both unusable for the mouse and keyboard and announced as
 * dimmed, so keep all three in step.
 */
export function setButtonDisabled(button: HTMLButtonElement, disabled: boolean) {
  button.disabled = disabled;
  if (disabled) button.setAttribute('aria-disabled', 'true'); else button.removeAttribute('aria-disabled');
  button.classList.toggle('fi-disabled', disabled);
}
