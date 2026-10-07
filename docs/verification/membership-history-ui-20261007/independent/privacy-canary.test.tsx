import { act, fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import CustomerLibrary from '../../resources/js/Pages/CustomerLibrary';
import { OwnedMembershipHistory } from '../../resources/js/components/OwnedMembershipHistory';
import { defaultSiteContent } from '../../resources/js/lib/site-content';
vi.mock('@inertiajs/react', async original => ({ ...await original<typeof import('@inertiajs/react')>(), Head: () => null }));
const json=(history:unknown)=>new Response(JSON.stringify({history}),{headers:{'Content-Type':'application/json'}});
const buckets={test_only:true,bucket_ids:[12]};
const balance={available:10,reserved:0,consumed:0,expired:0};
const history={bucket_id:12,plan_version_id:7,unit:'credit',expires_at:null,last_event_id:20,balance,spendable_credits:10,test_only:true,plan:{version_id:7,number:2,title:'PRIVATE OLD ACCOUNT CANARY',policy:{schema_version:1,unit:'credit',allowance:10,validity_seconds:null,rollover:'none',reversal_allowed:false}},events:[{id:20,sequence:1,kind:'grant',amount:10,created_at:'2026-01-01 00:00:00',balance}]};
const library=(scope='a'.repeat(32),enabled=true)=><CustomerLibrary testOnly customer={{name:'Same name'}} siteContent={defaultSiteContent} testMembershipsEnabled={enabled} membershipHistoryScope={scope}/>;
async function loaded(){fireEvent.click(screen.getByRole('button',{name:'Browse test credit buckets'}));await screen.findByRole('button',{name:'View test credit bucket 1'});fireEvent.click(screen.getByRole('button',{name:'View test credit bucket 1'}));await screen.findByText('PRIVATE OLD ACCOUNT CANARY · Version 2');}
describe('independent privacy generation canaries',()=>{
 it('clears committed private content immediately on same-name scope replacement and capability removal',async()=>{
  vi.spyOn(globalThis,'fetch').mockResolvedValueOnce(json(buckets)).mockResolvedValueOnce(json(history));const view=render(library());await loaded();
  view.rerender(library('b'.repeat(32)));expect(screen.queryByText(/PRIVATE OLD ACCOUNT CANARY/)).not.toBeInTheDocument();expect(screen.queryByRole('button',{name:'View test credit bucket 1'})).not.toBeInTheDocument();
  view.rerender(library('b'.repeat(32),false));expect(screen.queryByRole('region',{name:'Your test membership history'})).not.toBeInTheDocument();expect(sessionStorage.length).toBe(0);expect(localStorage.length).toBe(0);
 });
 it('never lets old pagehide completion overwrite a new completed request generation',async()=>{
  let old!:(v:Response)=>void;const fetcher=vi.spyOn(globalThis,'fetch').mockImplementationOnce(()=>new Promise(r=>{old=r;})).mockResolvedValueOnce(json({test_only:true,bucket_ids:[]}));render(<OwnedMembershipHistory/>);
  fireEvent.click(screen.getByRole('button',{name:'Browse test credit buckets'}));fireEvent(window,new Event('pagehide'));expect((fetcher.mock.calls[0][1]?.signal as AbortSignal).aborted).toBe(true);
  fireEvent.click(screen.getByRole('button',{name:'Browse test credit buckets'}));await screen.findByText('No test credit buckets belong to this account.');await act(async()=>{old(json(buckets));});
  expect(screen.queryByRole('button',{name:'View test credit bucket 1'})).not.toBeInTheDocument();expect(screen.getByText('No test credit buckets belong to this account.')).toBeInTheDocument();
 });
 it('aborts pending detail on unmount and prevents late private bytes from entering a fresh component',async()=>{
  let complete!:(v:Response)=>void;const fetcher=vi.spyOn(globalThis,'fetch').mockResolvedValueOnce(json(buckets)).mockImplementationOnce(()=>new Promise(r=>{complete=r;}));const old=render(<OwnedMembershipHistory/>);
  fireEvent.click(screen.getByRole('button',{name:'Browse test credit buckets'}));await screen.findByRole('button',{name:'View test credit bucket 1'});fireEvent.click(screen.getByRole('button',{name:'View test credit bucket 1'}));old.unmount();expect((fetcher.mock.calls[1][1]?.signal as AbortSignal).aborted).toBe(true);
  render(<OwnedMembershipHistory/>);await act(async()=>{complete(json(history));});expect(screen.queryByText(/PRIVATE OLD ACCOUNT CANARY/)).not.toBeInTheDocument();expect(fetcher).toHaveBeenCalledTimes(2);
 });
});
