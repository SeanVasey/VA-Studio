import type { CSSProperties } from 'react';

const paths = {
  arrow: <><path d="M4 12h15M13 5l7 7-7 7" /></>,
  northeast: <><path d="M6 18 18 6M6 6h12v12" /></>,
  play: <path d="m8 5 12 7-12 7Z" />,
  pause: <><path d="M8 5v14M16 5v14" /></>,
  bag: <><path d="M5 7h14l1 14H4L5 7Z" /><path d="M9 7V5a3 3 0 0 1 6 0v2" /></>,
  search: <><circle cx="10.5" cy="10.5" r="6.5" /><path d="m16 16 5 5" /></>,
  close: <><path d="M6 6 18 18M6 18 18 6" /></>,
  volume: <><path d="m11 4-6 5H2v6h3l6 5V4Z" /><path d="M15 8a6 6 0 0 1 0 8M18 4a11 11 0 0 1 0 16" /></>,
  mute: <><path d="m11 4-6 5H2v6h3l6 5V4Z" /><path d="m16 9 6 6m0-6-6 6" /></>,
  check: <path d="m5 12 4 4L20 5" />,
  share: <><path d="M12 15V3m-4 4 4-4 4 4M5 12v8h14v-8" /></>,
  chevron: <path d="m6 9 6 6 6-6" />,
  next: <><path d="m5 5 11 7-11 7Z" /><path d="M20 5v14" /></>,
  previous: <><path d="m19 5-11 7 11 7Z" /><path d="M4 5v14" /></>,
  music: <><path d="M9 18V5l12-2v13M9 9l12-2" /><ellipse cx="5" cy="18" rx="4" ry="3" /><ellipse cx="17" cy="16" rx="4" ry="3" /></>,
};

export type IconName = keyof typeof paths;
export function Icon({ name, size = 20, style }: { name: IconName; size?: number; style?: CSSProperties }) {
  return <svg width={size} height={size} viewBox="0 0 24 24" fill={name === 'play' ? 'currentColor' : 'none'} stroke="currentColor" strokeWidth="1.6" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true" style={style}>{paths[name]}</svg>;
}
