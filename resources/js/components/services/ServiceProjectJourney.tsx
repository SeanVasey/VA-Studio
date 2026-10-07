import { useEffect, useRef, useState, type FormEvent } from 'react';

export type ServiceDefinition = { versionId: number; version: number; hash: string; title: string; description: string; questions: string[] };
export type ServiceQuote = { id: string; hash: string; title: string; scope: string; currency: string; totalMinor: number; depositMinor: number; revisionAllowance: number; cancellation: string; milestones: { id: string; label: string; scope: string }[] };
export type ServiceProjectSummary = { id: string; title: string; version: number; status: string; submittedAt: string };
export type ServiceProject = ServiceProjectSummary & { testOnly: true; summary: string; answers: { question: string; answer: string }[]; quoteId: string | null; quoteHash: string | null; quotes: ServiceQuote[]; milestones: Record<string, string>; revisionsUsed: number; scopeFrozen: boolean; paymentState: 'not_collected'; deliveryAuthorized: false; history: { id: string; version: number; action: string; actor: 'staff' | 'buyer'; at: string; reason: string | null }[] };
export type ServiceProjectIndex = { schema: 1; testOnly: true; services: ServiceDefinition[]; projects: ServiceProjectSummary[] };
type Pending = { path: string; body: Record<string, unknown> };

function csrf(): Record<string, string> {
  const cookie = document.cookie.split('; ').find(value => value.startsWith('XSRF-TOKEN='));
  if (cookie) { try { return { 'X-XSRF-TOKEN': decodeURIComponent(cookie.slice(11)) }; } catch { /* Use the document token. */ } }
  const token = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
  return token ? { 'X-CSRF-TOKEN': token } : {};
}

export function ServiceProjectJourney({ initial }: { initial: ServiceProjectIndex }) {
  const [index, setIndex] = useState<ServiceProjectIndex | null>(initial);
  const [project, setProject] = useState<ServiceProject | null>(null);
  const [selected, setSelected] = useState('');
  const [summary, setSummary] = useState(''), [answers, setAnswers] = useState<string[]>([]);
  const [reason, setReason] = useState('');
  const [busy, setBusy] = useState(false), [message, setMessage] = useState(''), [pending, setPending] = useState<Pending | null>(null);
  const active = useRef(true), inFlight = useRef(false), requestController = useRef<AbortController | null>(null);
  const alert = useRef<HTMLDivElement | null>(null);
  const service = index?.services.find(item => String(item.versionId) === selected);
  const quote = project?.quotes.find(item => item.id === project.quoteId);

  useEffect(() => {
    active.current = true;
    const clear = () => { requestController.current?.abort(); setIndex(null); setProject(null); setSelected(''); setSummary(''); setAnswers([]); setReason(''); setPending(null); };
    const restore = (event: PageTransitionEvent) => { if (event.persisted) { clear(); setMessage('Refresh this page to check your current account access.'); } };
    window.addEventListener('pagehide', clear); window.addEventListener('pageshow', restore);
    return () => { active.current = false; requestController.current?.abort(); window.removeEventListener('pagehide', clear); window.removeEventListener('pageshow', restore); };
  }, []);
  useEffect(() => { if (message) alert.current?.focus(); }, [message]);

  async function read(path: string) {
    if (inFlight.current) return;
    inFlight.current = true; setBusy(true); setMessage('');
    setProject(null);
    const controller = new AbortController(); requestController.current = controller;
    const timeout = window.setTimeout(() => controller.abort(), 20_000);
    try {
      const response = await fetch(path, { credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller.signal, headers: { Accept: 'application/json' } });
      if (!active.current) return;
      if (!response.ok || response.redirected) {
        if ([403, 404, 419].includes(response.status)) { setIndex(null); setProject(null); setPending(null); setSelected(''); setSummary(''); setAnswers([]); setReason(''); }
        setMessage('This project is unavailable or changed. Refresh to check your current access.'); return;
      }
      const result = await response.json() as { project?: ServiceProject } & ServiceProjectIndex;
      if (!active.current) return;
      if (result.project) setProject(result.project); else setIndex(result);
      setPending(null);
    } catch { if (active.current) setMessage('Loading could not be confirmed. Try refreshing again.'); }
    finally { window.clearTimeout(timeout); inFlight.current = false; if (active.current) setBusy(false); }
  }

  async function save(command: Pending) {
    if (inFlight.current) return;
    inFlight.current = true; setBusy(true); setMessage(''); setPending(command);
    const controller = new AbortController(); requestController.current = controller;
    const timeout = window.setTimeout(() => controller.abort(), 20_000);
    try {
      const response = await fetch(command.path, { method: 'POST', credentials: 'same-origin', cache: 'no-store', redirect: 'error', signal: controller.signal,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', ...csrf() }, body: JSON.stringify(command.body) });
      if (!active.current) return;
      if (!response.ok || response.redirected) {
        if ([403, 404, 419].includes(response.status)) { setIndex(null); setProject(null); setPending(null); setSelected(''); setSummary(''); setAnswers([]); setReason(''); }
        setMessage(response.status === 409 ? 'The project changed. Refresh to inspect the saved journey before starting another action.' : 'Saving was not confirmed. Retry these exact contents or refresh to inspect the saved journey.'); return;
      }
      const result = await response.json() as { project: ServiceProject };
      if (!active.current) return;
      setProject(result.project); setPending(null); setReason(''); setSummary(''); setAnswers([]); setSelected('');
      setIndex(current => current ? { ...current, projects: [result.project, ...current.projects.filter(item => item.id !== result.project.id)].slice(0, 25) } : null);
      setMessage('Saved to your scope journey. No payment or delivery was authorized.');
    } catch { if (active.current) setMessage('Saving was not confirmed. Retry these exact contents; the same command will not be duplicated.'); }
    finally { window.clearTimeout(timeout); inFlight.current = false; if (active.current) setBusy(false); }
  }

  function submitBrief(event: FormEvent) {
    event.preventDefault();
    if (!service || busy || pending) return;
    void save({ path: '/services/projects', body: { requestKey: crypto.randomUUID(), serviceVersionId: service.versionId, serviceHash: service.hash, summary, answers } });
  }
  function command(action: string, extra: Record<string, unknown>) {
    if (!project || busy || pending) return;
    void save({ path: `/services/projects/${encodeURIComponent(project.id)}/commands`, body: { requestKey: crypto.randomUUID(), expectedVersion: project.version, action, ...extra } });
  }
  const disabled = busy || pending !== null;
  return <div className="service-project-journey" aria-busy={busy}>
    <p className="customer-account-note">Test scope preparation. Authored amounts and deposit requests remain uncollected. Acceptance records the exact scope; it does not create payment, download access or file delivery.</p>
    <div className="service-project-actions"><button type="button" className="button button-outline" disabled={busy} onClick={() => void read('/services/projects/data')}>Refresh projects</button><a className="text-link" href="/account">Back to your account</a></div>
    {message && <div role="alert" tabIndex={-1} ref={alert} className="customer-account-message">{message}{pending && <button type="button" className="button" disabled={busy} onClick={() => void save(pending)}>Retry exact saved contents</button>}</div>}
    {index && <>
      <section aria-label="Submit a private service brief"><h2>Start a service brief</h2>
        <form onSubmit={submitBrief}>
          <label htmlFor="service-definition">Service definition</label><select id="service-definition" required value={selected} disabled={disabled} onChange={event => { setSelected(event.target.value); const next = index.services.find(item => String(item.versionId) === event.target.value); setAnswers(next?.questions.map(() => '') ?? []); }}>
            <option value="">Choose a private test service</option>{index.services.map(item => <option key={item.versionId} value={item.versionId}>{item.title} · revision {item.version}</option>)}
          </select>
          {service && <><p>{service.description}</p><label htmlFor="service-summary">Your project brief</label><textarea id="service-summary" required maxLength={4000} value={summary} disabled={disabled} onChange={event => setSummary(event.target.value)} />
            {service.questions.map((question, position) => <div key={position}><label htmlFor={`service-answer-${position}`}>{question}</label><textarea id={`service-answer-${position}`} required maxLength={2000} value={answers[position] ?? ''} disabled={disabled} onChange={event => setAnswers(current => current.map((value, i) => i === position ? event.target.value : value))} /></div>)}
            <button type="submit" className="button" disabled={disabled}>Submit private brief</button></>}
          {index.services.length === 0 && <p>No private service definitions are available.</p>}
        </form>
      </section>
      <section aria-label="Your service projects"><h2>Your service projects</h2>{index.projects.length === 0 ? <p>No service briefs submitted yet.</p> : <ul>{index.projects.map(item => <li key={item.id}><button type="button" className="button button-outline" disabled={busy || pending !== null} onClick={() => void read(`/services/projects/${encodeURIComponent(item.id)}`)}>{item.title} · {item.status.replaceAll('_', ' ')}</button></li>)}</ul>}</section>
    </>}
    {project && <section aria-label="Service project journey">
      <h2>{project.title}</h2><p>Journey: <strong>{project.status.replaceAll('_', ' ')}</strong> · revision {project.version}</p><p className="service-project-copy">{project.summary}</p>
      {project.answers.map((answer, i) => <div key={i}><h3>{answer.question}</h3><p className="service-project-copy">{answer.answer}</p></div>)}
      {quote && <article aria-label="Exact authored quote"><h3>{quote.title}</h3><p className="service-project-copy">{quote.scope}</p><dl><dt>Authored total</dt><dd>{quote.currency} {quote.totalMinor} minor units</dd><dt>Requested deposit (uncollected)</dt><dd>{quote.currency} {quote.depositMinor} minor units</dd><dt>Included revisions</dt><dd>{quote.revisionAllowance} · used {project.revisionsUsed}</dd></dl>
        <h4>Supplied cancellation terms</h4><p className="service-project-copy">{quote.cancellation}</p>
        {project.scopeFrozen && <p>Accepted scope is frozen. Payment remains uncollected and delivery is unavailable.</p>}
        {project.status === 'quoted' && <div className="service-project-actions"><button type="button" className="button" disabled={disabled} onClick={() => command('accept_quote', { quoteId: quote.id, quoteHash: quote.hash })}>Accept this exact scope</button><button type="button" className="button button-outline" disabled={disabled} onClick={() => command('decline_quote', { quoteId: quote.id, quoteHash: quote.hash })}>Decline quote</button></div>}
        <h4>Milestones</h4><ol>{quote.milestones.map(milestone => <li key={milestone.id}><h5>{milestone.label}</h5><p className="service-project-copy">{milestone.scope}</p><p>{(project.milestones[milestone.id] ?? 'not accepted').replaceAll('_', ' ')}</p>
          {project.milestones[milestone.id] === 'ready_for_review' && project.status === 'awaiting_customer_review' && <div className="service-project-actions"><button type="button" className="button" disabled={disabled || !reason.trim()} onClick={() => command('approve_milestone', { milestoneId: milestone.id, reason })}>Approve milestone scope review</button><button type="button" className="button button-outline" disabled={disabled || !reason.trim() || project.revisionsUsed >= quote.revisionAllowance} onClick={() => command('request_revision', { milestoneId: milestone.id, reason })}>Request included revision</button></div>}</li>)}</ol>
      </article>}
      {['submitted', 'quoted', 'declined', 'accepted', 'in_progress', 'awaiting_customer_review', 'scope_reviewed'].includes(project.status) && <><label htmlFor="service-status-note">Review, revision or withdrawal note</label><textarea id="service-status-note" maxLength={2000} value={reason} disabled={disabled} onChange={event => setReason(event.target.value)} />
        <button type="button" className="button button-outline" disabled={disabled || !reason.trim()} onClick={() => command(['submitted', 'quoted', 'declined'].includes(project.status) ? 'withdraw' : 'request_cancellation', { reason })}>{['submitted', 'quoted', 'declined'].includes(project.status) ? 'Withdraw this brief' : 'Request cancellation review'}</button></>}
      <details><summary>Recent quote history</summary>{project.quotes.map(saved => <article key={saved.id}><h3>{saved.title}</h3><p className="service-project-copy">{saved.scope}</p><p>{saved.currency} {saved.totalMinor} minor units · deposit {saved.depositMinor} minor units (uncollected)</p></article>)}</details>
      <h3>Recent scope journey history</h3><ol>{project.history.map(event => <li key={event.id}>{event.at} · {event.actor} · {event.action.replaceAll('_', ' ')}{event.reason && <p className="service-project-copy">{event.reason}</p>}</li>)}</ol>
      {project.status === 'scope_reviewed' && <p>Scope review recorded. This project has no paid completion or deliverable-access authority.</p>}
    </section>}
  </div>;
}
