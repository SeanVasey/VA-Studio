import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it } from 'vitest';
import DesignPreview from '../../resources/js/test/DesignPreview';
import { fixtureTracks } from '../../resources/js/test/fixtures';

describe('isolated storefront preview', () => {
  it('starts with the real empty state and exposes keyboard-accessible, reloadable views', async () => {
    const user = userEvent.setup();
    render(<DesignPreview search="" />);
    expect(screen.getByRole('heading', { name: 'A NEW CHAPTER IN SOUND.' })).toBeInTheDocument();
    expect(screen.queryByRole('article', { name: /\[DEMO\]/ })).not.toBeInTheDocument();
    const views = within(screen.getByRole('navigation', { name: 'Preview views' }));
    expect(views.getByRole('link', { name: 'Empty catalog' })).toHaveAttribute('aria-current', 'page');
    expect(views.getByRole('link', { name: 'Empty catalog' })).toHaveAttribute('href', '?');
    expect(views.getByRole('link', { name: 'Sample catalog' })).toHaveAttribute('href', '?fixtures');
    expect(views.getByRole('link', { name: 'Track detail' })).toHaveAttribute('href', '?fixtures&detail=fixture-track-0');
    await user.tab();
    expect(views.getByRole('link', { name: 'Empty catalog' })).toHaveFocus();
    await user.tab();
    expect(views.getByRole('link', { name: 'Sample catalog' })).toHaveFocus();
    await user.tab();
    expect(views.getByRole('link', { name: 'Track detail' })).toHaveFocus();
  });

  it('exposes real search, license selection and cart interactions with explicit sample limits', async () => {
    const user = userEvent.setup();
    render(<DesignPreview search="?fixtures" />);
    expect(screen.getByRole('link', { name: 'Sample catalog' })).toHaveAttribute('aria-current', 'page');
    expect(screen.getByText(/Sample tracks, prices and terms are illustrative/)).toBeInTheDocument();
    expect(screen.getByText(/Audio and full license text are unavailable in these samples/)).toBeInTheDocument();
    await user.type(screen.getByRole('searchbox', { name: 'Search tracks' }), 'quiet');
    expect(screen.getAllByRole('article', { name: /\[DEMO\]/ })).toHaveLength(1);
    await user.click(screen.getByRole('button', { name: /^Choose license for / }));
    await user.click(screen.getByRole('button', { name: /Add license/ }));
    await user.click(screen.getByRole('button', { name: 'Open cart, 1 item' }));
    expect(within(screen.getByRole('dialog', { name: 'YOUR SELECTIONS.' })).getByText(fixtureTracks[2].title)).toBeInTheDocument();
    expect(screen.getByText('Checkout is not available yet. No payment will be taken and no license will be issued.')).toBeInTheDocument();
  });

  it('restores a selected sample from its URL through the production track detail component', () => {
    render(<DesignPreview search="?fixtures&detail=fixture-track-2" />);
    expect(screen.getByRole('heading', { name: fixtureTracks[2].title, level: 1 })).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Track detail' })).toHaveAttribute('aria-current', 'page');
    expect(screen.getAllByRole('button', { name: 'Read terms & choose' })).toHaveLength(3);
    expect(screen.getByRole('button', { name: `Play ${fixtureTracks[2].title}` })).toBeDisabled();
    expect(screen.getByText('This preview is unavailable.')).toBeInTheDocument();
  });

  it('recovers from an unknown sample detail without implying a published track exists', () => {
    render(<DesignPreview search="?fixtures&detail=not-a-published-track" />);
    expect(screen.getByText('That sample track is not available. Showing the sample catalog.')).toHaveAttribute('role', 'status');
    expect(screen.getByRole('link', { name: 'Sample catalog' })).toHaveAttribute('aria-current', 'page');
    expect(screen.getAllByRole('article', { name: /\[DEMO\]/ })).toHaveLength(4);
    expect(screen.queryByRole('heading', { name: 'not-a-published-track' })).not.toBeInTheDocument();
  });
});
