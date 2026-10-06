import { afterEach, describe, expect, it, vi } from 'vitest';
import { isPublicStorefrontPath, registerStorefrontOfflineRecovery } from '../../resources/js/lib/storefront-offline';

afterEach(() => vi.unstubAllGlobals());

const publicPaths = ['/', '/tracks/one-track', '/about', '/contact', '/blog', '/blog/one-post', '/videos/one-video'];
const privatePaths = ['/admin', '/admin/login', '/account', '/account/recovery', '/orders/one/status', '/orders/one/delivery/download', '/quotes/one', '/contact/inquiries', '/about/extra', '/inquiries/one', '/api/catalog', '/embed/one', '/tracks/one/offers/2/license', '/tracks/../admin', '//admin', '/blog/one/extra', '/Blog'];

function environment(path = '/', secure = true, embedded = false, supported = true) {
  const frame = {};
  const register = vi.fn().mockResolvedValue({ scope: 'https://storefront.example.test/' });
  vi.stubGlobal('window', { isSecureContext: secure, self: frame, top: embedded ? {} : frame, location: { pathname: path } });
  vi.stubGlobal('navigator', supported ? { serviceWorker: { register } } : {});
  return register;
}

describe('public connection-recovery registration', () => {
  it.each(publicPaths)('registers on the top-level secure public path %s', async path => {
    const register = environment(path);
    expect(isPublicStorefrontPath(path)).toBe(true);
    await expect(registerStorefrontOfflineRecovery()).resolves.toEqual({ scope: 'https://storefront.example.test/' });
    expect(register).toHaveBeenCalledExactlyOnceWith('/storefront-worker.js', { scope: '/', updateViaCache: 'none' });
  });

  it.each(privatePaths)('does not register on %s', async path => {
    const register = environment(path);
    expect(isPublicStorefrontPath(path)).toBe(false);
    await expect(registerStorefrontOfflineRecovery()).resolves.toBeUndefined();
    expect(register).not.toHaveBeenCalled();
  });

  it.each([
    ['insecure', false, false, true], ['embedded', true, true, true], ['unsupported', true, false, false],
  ] as const)('preserves online behavior in an %s context', async (_name, secure, embedded, supported) => {
    const register = environment('/', secure, embedded, supported);
    await expect(registerStorefrontOfflineRecovery()).resolves.toBeUndefined();
    expect(register).not.toHaveBeenCalled();
  });

  it('does not reject application startup when registration fails', async () => {
    const register = environment();
    register.mockRejectedValueOnce(new Error('Storage unavailable'));
    await expect(registerStorefrontOfflineRecovery()).resolves.toBeUndefined();
    expect(register).toHaveBeenCalledTimes(1);
  });

  it('handles a synchronous registration restriction', async () => {
    const register = environment();
    register.mockImplementationOnce(() => { throw new Error('Policy restriction'); });
    await expect(registerStorefrontOfflineRecovery()).resolves.toBeUndefined();
  });
});
