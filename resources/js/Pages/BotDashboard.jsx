import { useState, useEffect, useCallback } from "react";
import { Head, router } from "@inertiajs/react";
import axios from 'axios';

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

const fmt = {
    price: (v) => (v != null ? `$${Number(v).toFixed(4)}` : "—"),
    pct:   (v) => (v != null ? `${Number(v).toFixed(2)}%` : "—"),
    ms:    (v) => (v != null ? `${Number(v).toFixed(0)}ms` : "—"),
    score: (v) => (v != null ? Number(v).toFixed(4) : "—"),
    dt:    (date, time) => (date && time ? `${date} ${time}` : "—"),
};

const EVENT_COLORS = {
    tick:    "text-emerald-400",
    blocked: "text-amber-400",
    skipped: "text-slate-400",
    error:   "text-red-400",
    started: "text-sky-400",
    stopped: "text-slate-400",
    paused:  "text-amber-300",
};

const STATUS_CONFIG = {
    running: { label: "RUNNING",  dot: "bg-emerald-400 animate-pulse", text: "text-emerald-400" },
    paused:  { label: "PAUSED",   dot: "bg-amber-400",                 text: "text-amber-400"   },
    stopped: { label: "STOPPED",  dot: "bg-red-500",                   text: "text-red-400"     },
};

// ─────────────────────────────────────────────────────────────────────────────
// Sub-components
// ─────────────────────────────────────────────────────────────────────────────

function StatCard({ label, value, sub, accent = false }) {
    return (
        <div className={`border border-slate-700 bg-slate-900 p-4 ${accent ? "border-emerald-700" : ""}`}>
            <p className="text-[10px] tracking-widest text-slate-500 uppercase mb-1">{label}</p>
            <p className={`font-mono text-xl font-bold ${accent ? "text-emerald-400" : "text-slate-100"}`}>
                {value}
            </p>
            {sub && <p className="text-[10px] text-slate-500 mt-1 font-mono">{sub}</p>}
        </div>
    );
}

function ControlButton({ label, onClick, disabled, variant = "default" }) {
    const variants = {
        green:  "border-emerald-600 text-emerald-400 hover:bg-emerald-600 hover:text-white",
        amber:  "border-amber-600  text-amber-400  hover:bg-amber-600  hover:text-white",
        red:    "border-red-700    text-red-400    hover:bg-red-700    hover:text-white",
        default:"border-slate-600  text-slate-300  hover:bg-slate-700",
    };

    return (
        <button
            onClick={onClick}
            disabled={disabled}
            className={`border px-5 py-2 text-xs tracking-widest uppercase font-mono transition-colors duration-150
                        disabled:opacity-30 disabled:cursor-not-allowed ${variants[variant]}`}
        >
            {label}
        </button>
    );
}

function LogTable({ logs }) {
    const cols = [
        { key: "event",         label: "Event",     render: (r) => (
            <span className={`uppercase text-[10px] tracking-widest ${EVENT_COLORS[r.event] ?? "text-slate-300"}`}>
                {r.event}
            </span>
        )},
        { key: "datetime",      label: "Time",       render: (r) => <span className="text-slate-400">{fmt.dt(r.date, r.time)}</span> },
        { key: "price",         label: "Price",      render: (r) => fmt.price(r.price)    },
        { key: "atr",           label: "ATR",        render: (r) => fmt.price(r.atr)      },
        { key: "action",        label: "Action",     render: (r) => r.action ?? "—"       },
        { key: "bias",          label: "Bias",       render: (r) => r.bias ?? "—"         },
        { key: "momentumScore", label: "Momentum",   render: (r) => fmt.score(r.momentumScore) },
        { key: "equity",        label: "Equity",     render: (r) => fmt.price(r.equity)   },
        { key: "drawdownPct",   label: "Drawdown",   render: (r) => (
            <span className={r.drawdownPct > 15 ? "text-red-400" : r.drawdownPct > 8 ? "text-amber-400" : ""}>
                {fmt.pct(r.drawdownPct)}
            </span>
        )},
        { key: "cash",          label: "Cash",       render: (r) => fmt.price(r.cash)     },
        { key: "shares",        label: "Shares",     render: (r) => r.shares ?? "—"       },
        { key: "gatePassed",    label: "Gate",       render: (r) => r.gatePassed == null ? "—"
            : r.gatePassed
                ? <span className="text-emerald-400">✓</span>
                : <span className="text-red-400">✗</span>
        },
        { key: "gateReason",    label: "Reason",     render: (r) => <span className="text-slate-500 text-[10px]">{r.gateReason ?? ""}</span> },
        { key: "durationMs",    label: "Duration",   render: (r) => fmt.ms(r.durationMs)  },
    ];

    return (
        <div className="overflow-x-auto">
            <table className="w-full text-xs font-mono border-collapse">
                <thead>
                    <tr className="border-b border-slate-700">
                        {cols.map((c) => (
                            <th key={c.key} className="text-left text-[10px] tracking-widest text-slate-500 uppercase px-3 py-2 whitespace-nowrap">
                                {c.label}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {logs.length === 0 && (
                        <tr>
                            <td colSpan={cols.length} className="text-center text-slate-600 py-10 tracking-widest text-[10px] uppercase">
                                No log entries yet
                            </td>
                        </tr>
                    )}
                    {logs.map((row) => (
                        <tr
                            key={row.id}
                            className="border-b border-slate-800 hover:bg-slate-800/50 transition-colors"
                        >
                            {cols.map((c) => (
                                <td key={c.key} className="px-3 py-2 whitespace-nowrap text-slate-300">
                                    {c.render(row)}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function SettingsPanel({ bot, onSaved }) {
    const [form, setForm] = useState({
        ticker:             bot.ticker,
        drawdown_limit_pct: bot.drawdownLimitPct,
        interval_mode:      bot.intervalMode,
        test_mode:          bot.testMode,
    });
    const [saving, setSaving] = useState(false);
    const [flash, setFlash]   = useState(null);

    const save = async () => {
        setSaving(true);
        try {
            const res = await fetch("/bot/settings", {
                method: "PUT",
                headers: { "Content-Type": "application/json", "X-CSRF-TOKEN": csrfToken() },
                body: JSON.stringify(form),
            });
            const data = await res.json();
            if (!res.ok) throw new Error(data.message ?? "Save failed");
            setFlash("Saved");
            onSaved(data.bot);
        } catch (e) {
            setFlash(`Error: ${e.message}`);
        } finally {
            setSaving(false);
            setTimeout(() => setFlash(null), 2500);
        }
    };

    return (
        <div className="border border-slate-700 bg-slate-900 p-5 space-y-4">
            <p className="text-[10px] tracking-widest text-slate-500 uppercase">Settings</p>

            <div className="grid grid-cols-2 gap-4 sm:grid-cols-4">
                <label className="flex flex-col gap-1">
                    <span className="text-[10px] text-slate-500 uppercase tracking-widest">Ticker</span>
                    <input
                        type="text"
                        value={form.ticker}
                        onChange={(e) => setForm({ ...form, ticker: e.target.value.toUpperCase() })}
                        className="bg-slate-800 border border-slate-700 text-slate-100 font-mono text-sm px-3 py-1.5 focus:outline-none focus:border-emerald-600"
                        maxLength={10}
                    />
                </label>

                <label className="flex flex-col gap-1">
                    <span className="text-[10px] text-slate-500 uppercase tracking-widest">Drawdown Limit %</span>
                    <input
                        type="number"
                        min={1}
                        max={100}
                        value={form.drawdown_limit_pct}
                        onChange={(e) => setForm({ ...form, drawdown_limit_pct: e.target.value })}
                        className="bg-slate-800 border border-slate-700 text-slate-100 font-mono text-sm px-3 py-1.5 focus:outline-none focus:border-emerald-600"
                    />
                </label>

                <label className="flex flex-col gap-1">
                    <span className="text-[10px] text-slate-500 uppercase tracking-widest">Interval Mode</span>
                    <select
                        value={form.interval_mode}
                        onChange={(e) => setForm({ ...form, interval_mode: e.target.value })}
                        className="bg-slate-800 border border-slate-700 text-slate-100 font-mono text-sm px-3 py-1.5 focus:outline-none focus:border-emerald-600"
                    >
                        <option value="test">Test (1 min)</option>
                        <option value="live">Live (:35 ET)</option>
                    </select>
                </label>

                <label className="flex flex-col gap-1">
                    <span className="text-[10px] text-slate-500 uppercase tracking-widest">Test Mode</span>
                    <div className="flex items-center h-[34px]">
                        <input
                            type="checkbox"
                            checked={form.test_mode}
                            onChange={(e) => setForm({ ...form, test_mode: e.target.checked })}
                            className="w-4 h-4 accent-emerald-500"
                        />
                        <span className="ml-2 text-slate-400 font-mono text-xs">Bypass market hours</span>
                    </div>
                </label>
            </div>

            <div className="flex items-center gap-4">
                <ControlButton label={saving ? "Saving…" : "Save Settings"} onClick={save} disabled={saving} variant="green" />
                {flash && <span className="text-xs font-mono text-slate-400">{flash}</span>}
            </div>
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// CSRF helper
// ─────────────────────────────────────────────────────────────────────────────

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? "";
}

// ─────────────────────────────────────────────────────────────────────────────
// Main Dashboard
// ─────────────────────────────────────────────────────────────────────────────

export default function Dashboard({ bot: initialBot, logs: initialLogs }) {
    const [bot,  setBot]  = useState(initialBot);
    const [logs, setLogs] = useState(initialLogs);
    const [busy, setBusy] = useState(false);
    const [page, setPage] = useState(1);
    const [lastPage, setLastPage] = useState(1);
    const [filterEvent, setFilterEvent] = useState("");
    const [logsLoading, setLogsLoading] = useState(false);

    // ── Polling ───────────────────────────────────────────────────────────
    useEffect(() => {
        const interval = setInterval(async () => {
            try {
                const res  = await fetch("/bot/status");
                const data = await res.json();
                setBot(data.bot);
            } catch {}
        }, 5000);

        return () => clearInterval(interval);
    }, []);

    // ── Fetch paginated logs ──────────────────────────────────────────────
    const fetchLogs = useCallback(async (p = 1, event = "") => {
        setLogsLoading(true);
        try {
            const params = new URLSearchParams({ page: p, per_page: 50 });
            if (event) params.set("event", event);
            const res  = await fetch(`/bot/logs?${params}`);
            const data = await res.json();
            setLogs(data.data);
            setPage(data.current_page);
            setLastPage(data.last_page);
        } catch {}
        setLogsLoading(false);
    }, []);

    // Refresh logs every 10s when running
    useEffect(() => {
        if (bot.status !== "running") return;
        const id = setInterval(() => fetchLogs(page, filterEvent), 10_000);
        return () => clearInterval(id);
    }, [bot.status, page, filterEvent, fetchLogs]);

window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

const token = document
    .querySelector('meta[name="csrf-token"]')
    ?.getAttribute('content');

if (token) {
    window.axios.defaults.headers.common['X-CSRF-TOKEN'] = token;
}
    // ── Controls ──────────────────────────────────────────────────────────
    const control = async (action) => {
        setBusy(true);
        try {
            const res  = await fetch(`/bot/${action}`, {
                method: "POST",
                headers: { "X-CSRF-TOKEN": csrfToken(), "Content-Type": "application/json" },
            });
            const data = await res.json();
            if (data.bot) setBot(data.bot);
            // Refresh logs after control action
            await fetchLogs(1, filterEvent);
        } catch {}
        setBusy(false);
    };

    const sc = STATUS_CONFIG[bot.status] ?? STATUS_CONFIG.stopped;
    const isRunning = bot.status === "running";
    const isPaused  = bot.status === "paused";
    const isStopped = bot.status === "stopped";

    return (
        <>
            <Head title="Bot Dashboard" />

            {/* ── Page shell ─────────────────────────────────────────── */}
            <div className="min-h-screen bg-slate-950 text-slate-100" style={{ fontFamily: "'JetBrains Mono', 'Fira Code', monospace" }}>

                {/* Header */}
                <div className="border-b border-slate-800 px-6 py-4 flex items-center justify-between">
                    <div className="flex items-center gap-3">
                        <div className={`w-2 h-2 rounded-full ${sc.dot}`} />
                        <span className="text-xs tracking-widest uppercase text-slate-400">Trading Bot</span>
                        <span className={`text-xs tracking-widest font-bold ${sc.text}`}>{sc.label}</span>
                        <span className="text-slate-600">·</span>
                        <span className="text-xs text-slate-500">{bot.ticker}</span>
                        <span className="text-slate-600">·</span>
                        <span className="text-xs text-slate-500 uppercase">{bot.intervalMode} mode</span>
                    </div>
                    {bot.lastError && (
                        <span className="text-[10px] text-red-400 font-mono max-w-sm truncate" title={bot.lastError}>
                            ERR: {bot.lastError}
                        </span>
                    )}
                </div>

                <div className="px-6 py-6 space-y-6">

                    {/* ── Stats row ──────────────────────────────────── */}
                    <div className="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-6 gap-3">
                        <StatCard label="Status"      value={sc.label}                                      accent={isRunning} />
                        <StatCard label="Total Ticks"  value={bot.totalTicks}    sub="since start"                            />
                        <StatCard label="Total Trades" value={bot.totalTrades}                                               />
                        <StatCard label="Last Action"  value={bot.lastAction ?? "—"}                                         />
                        <StatCard label="Last Run"     value={bot.lastRunAt ? new Date(bot.lastRunAt).toLocaleTimeString() : "—"} />
                        <StatCard label="Next Run"     value={bot.nextRunAt ? new Date(bot.nextRunAt).toLocaleTimeString() : "—"}
                                  sub={bot.intervalMode === "test" ? "every 1 min" : "hourly :35 ET"}       />
                    </div>

                    {/* ── Controls ───────────────────────────────────── */}
                    <div className="border border-slate-700 bg-slate-900 px-5 py-4 flex flex-wrap items-center gap-3">
                        <span className="text-[10px] tracking-widest text-slate-500 uppercase mr-2">Controls</span>

                        <ControlButton
                            label="▶ Start"
                            variant="green"
                            disabled={busy || isRunning}
                            onClick={() => control("start")}
                        />
                        <ControlButton
                            label="⏸ Pause"
                            variant="amber"
                            disabled={busy || !isRunning}
                            onClick={() => control("pause")}
                        />
                        <ControlButton
                            label="■ Stop"
                            variant="red"
                            disabled={busy || isStopped}
                            onClick={() => control("stop")}
                        />

                        {isPaused && (
                            <ControlButton
                                label="▶ Resume"
                                variant="green"
                                disabled={busy}
                                onClick={() => control("start")}
                            />
                        )}
                    </div>

                    {/* ── Settings ───────────────────────────────────── */}
                    <SettingsPanel bot={bot} onSaved={(updated) => setBot(updated)} />

                    {/* ── Log Table ──────────────────────────────────── */}
                    <div className="border border-slate-700 bg-slate-900">
                        <div className="flex items-center justify-between px-5 py-3 border-b border-slate-800">
                            <span className="text-[10px] tracking-widest text-slate-500 uppercase">
                                Activity Log
                                {logsLoading && <span className="ml-2 text-emerald-500 animate-pulse">↻</span>}
                            </span>
                            <div className="flex items-center gap-3">
                                {/* Event filter */}
                                <select
                                    value={filterEvent}
                                    onChange={(e) => { setFilterEvent(e.target.value); fetchLogs(1, e.target.value); }}
                                    className="bg-slate-800 border border-slate-700 text-slate-300 font-mono text-[10px] px-2 py-1 uppercase tracking-widest focus:outline-none focus:border-emerald-600"
                                >
                                    <option value="">All Events</option>
                                    <option value="tick">Tick</option>
                                    <option value="blocked">Blocked</option>
                                    <option value="skipped">Skipped</option>
                                    <option value="error">Error</option>
                                    <option value="started">Started</option>
                                    <option value="stopped">Stopped</option>
                                    <option value="paused">Paused</option>
                                </select>
                                <button
                                    onClick={() => fetchLogs(page, filterEvent)}
                                    className="text-[10px] tracking-widest text-slate-500 hover:text-emerald-400 uppercase"
                                >
                                    Refresh
                                </button>
                            </div>
                        </div>

                        <LogTable logs={logs} />

                        {/* Pagination */}
                        {lastPage > 1 && (
                            <div className="flex items-center justify-between px-5 py-3 border-t border-slate-800">
                                <span className="text-[10px] text-slate-500">
                                    Page {page} / {lastPage}
                                </span>
                                <div className="flex gap-2">
                                    <button
                                        disabled={page <= 1}
                                        onClick={() => fetchLogs(page - 1, filterEvent)}
                                        className="text-[10px] tracking-widest text-slate-400 hover:text-white disabled:opacity-30 uppercase"
                                    >
                                        ← Prev
                                    </button>
                                    <button
                                        disabled={page >= lastPage}
                                        onClick={() => fetchLogs(page + 1, filterEvent)}
                                        className="text-[10px] tracking-widest text-slate-400 hover:text-white disabled:opacity-30 uppercase"
                                    >
                                        Next →
                                    </button>
                                </div>
                            </div>
                        )}
                    </div>

                </div>
            </div>
        </>
    );
}
