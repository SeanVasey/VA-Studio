import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { expect, it } from 'vitest';
import { Modal } from '../../resources/js/components/Modal';

function Fixture({ extra = null }: { extra?: React.ReactNode }) {
  return <Modal title="Dialog" onClose={() => {}}>
    <a href="#terms">Terms</a>
    <button type="button" disabled>Unavailable</button>
    <div hidden><button type="button">Hidden</button></div>
    <button type="button">Confirm</button>
    {extra}
  </Modal>;
}

it('wraps Shift+Tab from the first control to the last enabled, visible control', async () => {
  const user = userEvent.setup();
  render(<Fixture />);
  screen.getByRole('button', { name: 'Close dialog', hidden: true }).focus();
  await user.tab({ shift: true });
  expect(screen.getByRole('button', { name: 'Confirm', hidden: true })).toHaveFocus();
});

it('wraps Tab from the last control back to the Close button and keeps interior steps native', async () => {
  const user = userEvent.setup();
  render(<Fixture />);
  const close = screen.getByRole('button', { name: 'Close dialog', hidden: true });
  const confirm = screen.getByRole('button', { name: 'Confirm', hidden: true });
  confirm.focus();
  await user.tab();
  expect(close).toHaveFocus();
  await user.tab();
  expect(screen.getByRole('link', { name: 'Terms', hidden: true })).toHaveFocus();
});

it('leaves modified Tab presses alone and ignores other keys', async () => {
  const user = userEvent.setup();
  render(<Fixture />);
  const confirm = screen.getByRole('button', { name: 'Confirm', hidden: true });
  confirm.focus();
  const event = new KeyboardEvent('keydown', { key: 'Tab', ctrlKey: true, bubbles: true, cancelable: true });
  confirm.dispatchEvent(event);
  expect(event.defaultPrevented).toBe(false);
  await user.keyboard('a');
  expect(confirm).toHaveFocus();
});
