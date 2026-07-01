import { useState, useCallback, useEffect } from "react";
import {
  LineChart, Line, AreaChart, Area, XAxis, YAxis,
  Tooltip, ResponsiveContainer, ReferenceLine, BarChart, Bar, Cell
} from "recharts";
import AuthenticatedLayout from "@/Layouts/AuthenticatedLayout";

const C = {
  bg:       "#0a0b0e",
  surface:  "#0f1117",
  panel:    "#13161e",
  border:   "#1e2330",
  borderHi: "#2a3040",
  text:     "#e2e8f0",
  muted:    "#4a5568",
  buy:      "#00d97e",
  buyDim:   "rgba(0,217,126,0.08)",
  sell:     "#ff6b35",
  sellDim:  "rgba(255,107,53,0.08)",
  amber:    "#f6ad55",
  blue:     "#63b3ed",
  red:      "#fc5c65",
  head:     "#8892a4",
};

const mono = { fontFamily: "'JetBrains Mono', 'Fira Code', monospace" };
const r2   = (v) => Math.round((v ?? 0) * 100) / 100;

// ── Normalise settings: accept both snake_case (from DB) and camelCase ────────
function normSettings(raw = {}) {
  return {
    ticker:             raw.ticker             ?? raw.ticker             ?? "—",
    initialShares:      raw.initialShares      ?? raw.initial_shares     ?? 0,
    initialCash:        raw.initialCash        ?? raw.initial_cash       ?? 0,
    tradeFee:           raw.tradeFee           ?? raw.trade_fee          ?? 5,
    minFeeCover:        raw.minFeeCover        ?? raw.min_fee_cover      ?? 2,
    minMoveAtrMult:     raw.minMoveAtrMult     ?? raw.min_move_atr_mult  ?? 0.15,
    momentumLookback:   raw.momentumLookback   ?? raw.momentum_lookback  ?? 3,
    strongMomLookback:  raw.strongMomLookback  ?? raw.strong_mom_lookback ?? 7,
    minQty:             raw.minQty             ?? raw.min_qty            ?? 5,
    minBuyDipPct:       raw.minBuyDipPct       ?? raw.min_buy_dip_pct    ?? 0,
    atrLevels:          raw.atrLevels          ?? raw.atr_levels         ?? [0.5, 1.0, 1.5],
    baseFractions:      raw.baseFractions      ?? raw.base_fractions     ?? [0.3, 0.4, 0.3],
    maxDrawdown:        raw.maxDrawdown        ?? raw.max_drawdown       ?? 0.25,
    maxTicks:           raw.maxTicks           ?? raw.max_ticks          ?? 50,
  };
}

async function apiCall(method, url, data) {
    // Get fresh CSRF token first
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    console.log('[apiCall]', method, url, 'csrf token:', token ? token.substring(0, 10) + '...' : 'MISSING');

    const res = await fetch(url, {
        method,
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
        },
        body: data ? JSON.stringify(data) : undefined,
    });

    // CSRF expired — refresh token and retry once
    if (res.status === 419) {
        console.warn('[apiCall] 419 CSRF mismatch — refreshing token and retrying...');
        await fetch('/sanctum/csrf-cookie');                          // re-hydrate cookie
        const freshToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        const retry = await fetch(url, {
            method,
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': freshToken,
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            },
            body: data ? JSON.stringify(data) : undefined,
        });
        return retry.json();
    }

    return res.json();
}

// ─── UI primitives ────────────────────────────────────────────────────────────

function Tag({ children, color = C.muted, bg = "transparent" }) {
  return (
    <span style={{
      ...mono, fontSize: 10, color, background: bg,
      border: `1px solid ${color}33`,
      padding: "2px 7px", borderRadius: 3, whiteSpace: "nowrap", letterSpacing: "0.04em",
    }}>
      {children}
    </span>
  );
}

function ActionTag({ action = "" }) {
  const isBuy  = action?.includes("BUY");
  const isSell = action?.includes("SELL");
  return (
    <Tag color={isBuy ? C.buy : isSell ? C.sell : C.muted}
         bg={isBuy ? C.buyDim : isSell ? C.sellDim : "transparent"}>
      {action || "HOLD"}
    </Tag>
  );
}

function Metric({ label, value, sub, color, danger }) {
  return (
    <div style={{ background: C.panel, border: `1px solid ${C.border}`, borderRadius: 6, padding: "11px 14px" }}>
      <div style={{ fontSize: 9, color: C.muted, textTransform: "uppercase", letterSpacing: "0.12em", marginBottom: 5, fontFamily: "system-ui" }}>
        {label}
      </div>
      <div style={{ ...mono, fontSize: 14, fontWeight: 600, color: danger ? C.red : (color || C.text) }}>
        {value ?? "—"}
      </div>
      {sub && <div style={{ ...mono, fontSize: 9, color: C.muted, marginTop: 3 }}>{sub}</div>}
    </div>
  );
}

function Panel({ children, style = {} }) {
  return (
    <div style={{ background: C.panel, border: `1px solid ${C.border}`, borderRadius: 8, padding: "14px 18px", ...style }}>
      {children}
    </div>
  );
}

function SectionLabel({ children }) {
  return (
    <div style={{ fontSize: 9, color: C.muted, textTransform: "uppercase", letterSpacing: "0.14em", marginBottom: 8, fontFamily: "system-ui", fontWeight: 600 }}>
      {children}
    </div>
  );
}

function Input({ label, value, onChange, type = "number", step = "0.01", options }) {
  const s = { ...mono, fontSize: 13, background: C.bg, border: `1px solid ${C.border}`, color: C.text, borderRadius: 4, padding: "7px 10px", width: "100%", outline: "none" };
  return (
    <div>
      <div style={{ fontSize: 9, color: C.muted, textTransform: "uppercase", letterSpacing: "0.1em", marginBottom: 5, fontFamily: "system-ui" }}>{label}</div>
      {options
        ? <select value={value} onChange={e => onChange(e.target.value)} style={{ ...s, height: 34 }}>{options.map(o => <option key={o}>{o}</option>)}</select>
        : <input type={type} step={step} value={value} onChange={e => onChange(e.target.value)} style={s} />
      }
    </div>
  );
}

function Btn({ children, onClick, variant = "default", disabled, style = {} }) {
  const v = {
    default: { background: C.panel,        border: `1px solid ${C.borderHi}`, color: C.text  },
    primary: { background: C.buy  + "22",  border: `1px solid ${C.buy}55`,    color: C.buy   },
    danger:  { background: C.red  + "15",  border: `1px solid ${C.red}55`,    color: C.red   },
    sell:    { background: C.sell + "18",  border: `1px solid ${C.sell}55`,   color: C.sell  },
    amber:   { background: C.amber + "18", border: `1px solid ${C.amber}55`,  color: C.amber },
  };
  return (
    <button onClick={onClick} disabled={disabled}
      style={{ ...mono, fontSize: 12, padding: "8px 16px", borderRadius: 5, cursor: "pointer", letterSpacing: "0.05em", opacity: disabled ? 0.5 : 1, transition: "all 0.15s", ...v[variant], ...style }}>
      {children}
    </button>
  );
}

function TabBar({ tabs, active, onChange }) {
  return (
    <div style={{ display: "flex", gap: 2, borderBottom: `1px solid ${C.border}`, marginBottom: 14 }}>
      {tabs.map(t => (
        <button key={t.id} onClick={() => onChange(t.id)} style={{
          ...mono, fontSize: 11, padding: "7px 16px", cursor: "pointer",
          background: "transparent", border: "none",
          borderBottom: `2px solid ${active === t.id ? C.buy : "transparent"}`,
          color: active === t.id ? C.buy : C.muted, letterSpacing: "0.06em", marginBottom: -1,
        }}>
          {t.label}
        </button>
      ))}
    </div>
  );
}

const ChartTip = ({ active, payload }) => {
  if (!active || !payload?.length) return null;
  return (
    <div style={{ background: C.surface, border: `1px solid ${C.border}`, borderRadius: 5, padding: "6px 10px" }}>
      {payload.map((p, i) => (
        <div key={i} style={{ ...mono, fontSize: 11, color: p.color }}>
          {p.name}: {typeof p.value === "number" ? p.value.toFixed(2) : p.value}
        </div>
      ))}
    </div>
  );
};

function Toast({ msg }) {
  if (!msg) return null;
  const isBuy  = msg?.includes("BUY");
  const isSell = msg?.includes("SELL");
  const isUndo = msg?.includes("Undo");
  return (
    <div style={{
      position: "fixed", bottom: 24, right: 24, zIndex: 999,
      background: C.panel,
      border: `1px solid ${isBuy ? C.buy : isSell ? C.sell : isUndo ? C.amber : C.borderHi}`,
      borderRadius: 6, padding: "9px 16px", boxShadow: "0 8px 32px rgba(0,0,0,0.6)",
      ...mono, fontSize: 12, color: isBuy ? C.buy : isSell ? C.sell : isUndo ? C.amber : C.text,
    }}>
      {msg}
    </div>
  );
}

// ─── DASHBOARD ────────────────────────────────────────────────────────────────
function DashboardPage({ session, logRows, onRefresh, onDelete, onUndo, undoSnapshot }) {
  const last = logRows[logRows.length - 1] ?? null;
  const st   = session?.state ?? {};
  const cfg  = normSettings(session?.settings);

  const [price,    setPrice]    = useState(String(last?.price     ?? ""));
  const [atr,      setAtr]      = useState(String(last?.atr       ?? ""));
  const [yest,     setYest]     = useState(String(last?.yestClose ?? ""));
  const [inputTab, setInputTab] = useState("auto");
  const [mSide,    setMSide]    = useState("BUY");
  const [mPrice,   setMPrice]   = useState(String(last?.price ?? ""));
  const [mQty,     setMQty]     = useState("10");
  const [err,      setErr]      = useState("");
  const [loading,  setLoading]  = useState(false);
  useEffect(() => {
    if (last) {
      setAtr(String(last.atr ?? ""));
      setYest(String(last.yestClose ?? ""));
    }
  }, [last?.id]);

  // Live metrics — recalculate whenever price input OR session state changes
  const priceF    = parseFloat(price) || 0;
  const shares    = st.shares    ?? 0;
  const cash      = st.cash      ?? 0;
  const avgPrice  = st.avgPrice  ?? 0;
  const peakEq    = st.peakEquity ?? 0;

  const liveEq    = r2(cash + shares * priceF);
  const unrealPL  = r2((priceF - avgPrice) * shares);
  const dd        = peakEq > 0 ? r2((peakEq - liveEq) / peakEq * 100) : 0;
  const costBasis = r2(shares * avgPrice);
  const atrPct    = last ? r2((last.atr / last.price) * 100) : null;
  const maxDDPct  = (cfg.maxDrawdown ?? 0.25) * 100;
  const ddRoom    = last ? r2(maxDDPct - (last.drawdown ?? 0)) : null;

  async function runAuto() {
    setErr(""); setLoading(true);
    const p = parseFloat(price), y = parseFloat(yest), a = parseFloat(atr);
    if ([p, y, a].some(isNaN) || [p, y, a].some(v => v <= 0)) {
      setErr("All three inputs required and > 0"); setLoading(false); return;
    }
    const res = await apiCall("POST", "/api/trading/tick", { price: p, yest_close: y, atr: a });
    setLoading(false);
    if (res.error) { setErr(String(res.error)); return; }
    console.log("tick res:", res);
    onRefresh(res.session, res.logRow);
console.log("session from API:", res.session);

  }

  async function runManual() {
    setErr(""); setLoading(true);
    const p = parseFloat(mPrice), q = parseInt(mQty);
    if (isNaN(p) || p <= 0 || isNaN(q) || q <= 0) { setErr("Invalid price or qty"); setLoading(false); return; }
    const res = await apiCall("POST", "/api/trading/manual", { side: mSide, price: p, qty: q });
    setLoading(false);
    if (res.error) { setErr(String(res.error)); return; }
    onRefresh(res.session, res.logRow);
  }

  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 12 }}>

      {/* ROW 1: Position metrics — driven entirely by session.state */}
      <div style={{ display: "grid", gridTemplateColumns: "repeat(6,1fr)", gap: 8 }}>
        <Metric label="Shares Held"
          value={shares}
          sub={avgPrice > 0 ? `avg $${r2(avgPrice)}` : "no position"} />
        <Metric label="Cost Basis"
          value={`$${costBasis}`}
          color={C.blue} />
        <Metric label="Cash"
          value={`$${r2(cash)}`}
          color={C.muted} />
        <Metric label="Live Equity"
          value={`$${liveEq}`}
          sub={peakEq > 0 ? `peak $${r2(peakEq)}` : undefined}
          color={C.blue} />
        <Metric label="Unrealized P&L"
          value={`$${unrealPL}`}
          color={unrealPL >= 0 ? C.buy : C.red} />
        <Metric label="Drawdown"
          value={`${dd}%`}
          danger={dd > 15}
          sub={ddRoom !== null ? `${ddRoom}% headroom` : undefined} />
      </div>

      {/* ROW 2: Signal metrics — from last log row */}
      {last && (
        <div style={{ display: "grid", gridTemplateColumns: "repeat(6,1fr)", gap: 8 }}>
          <Metric label="Bias"
            value={last.bias ?? "—"}
            color={last.bias === "UP" ? C.buy : last.bias === "DOWN" ? C.sell : C.muted} />
          <Metric label="Mom Score"
            value={r2(last.momentumScore)}
            color={Math.abs(last.momentumScore ?? 0) >= 2.5 ? C.amber : C.muted} />
          <Metric label="ATR %"
            value={atrPct !== null ? `${atrPct}%` : "—"}
            color={C.muted} />
          <Metric label="Ticks"
            value={`${last.tick} / ${st.maxTicks}`}
            color={C.amber} />
          <Metric label="Last Action"
            value={<ActionTag action={last.action} />} />
          <Metric label="Realized P&L"
            value={last.realizedPL !== 0 ? `$${r2(last.realizedPL)}` : "—"}
            color={(last.realizedPL ?? 0) > 0 ? C.buy : (last.realizedPL ?? 0) < 0 ? C.red : C.muted} />
        </div>
      )}

      {/* Last Signal Panel */}
      {last && (
        <Panel>
          <SectionLabel>Last Signal · {last.date} {last.time}</SectionLabel>
          <div style={{ display: "flex", flexWrap: "wrap", gap: 5, marginBottom: 8 }}>
            <ActionTag action={last.action} />
            <Tag>@ ${last.price}</Tag>
            <Tag>ATR {last.atr}</Tag>
            <Tag>Yest ${last.yestClose}</Tag>
            {last.bias && last.bias !== "NONE" && (
              <Tag color={last.bias === "UP" ? C.buy : C.sell}>bias: {last.bias}</Tag>
            )}
            {last.momentumScore !== 0 && last.momentumScore != null && (
              <Tag color={C.amber}>mom score: {r2(last.momentumScore)}</Tag>
            )}
            {atrPct !== null && <Tag color={C.muted}>ATR {atrPct}%</Tag>}
            {ddRoom !== null && (
              <Tag color={ddRoom < 5 ? C.red : ddRoom < 10 ? C.amber : C.muted}>
                DD room: {ddRoom}%
              </Tag>
            )}
            {last.unrealizedPL != null && (
              <Tag color={(last.unrealizedPL ?? 0) >= 0 ? C.buy : C.red}>
                unreal: ${r2(last.unrealizedPL)}
              </Tag>
            )}
          </div>
          {(last.buyL1 || last.buyL2 || last.buyL3) && (
            <div style={{ display: "flex", flexWrap: "wrap", gap: 5, marginBottom: 5 }}>
              {[last.buyL1, last.buyL2, last.buyL3].filter(Boolean).map((l, i) => (
                <Tag key={i} color={C.buy} bg={C.buyDim}>BUY L{i + 1}: {l}</Tag>
              ))}
            </div>
          )}
          {(last.sellL1 || last.sellL2 || last.sellL3) && (
            <div style={{ display: "flex", flexWrap: "wrap", gap: 5 }}>
              {[last.sellL1, last.sellL2, last.sellL3].filter(Boolean).map((l, i) => (
                <Tag key={i} color={C.sell} bg={C.sellDim}>SELL L{i + 1}: {l}</Tag>
              ))}
            </div>
          )}
        </Panel>
      )}

      {/* Input Panel */}
      <Panel>
        <TabBar
          tabs={[{ id: "auto", label: "AUTO TICK" }, { id: "manual", label: "MANUAL OVERRIDE" }]}
          active={inputTab} onChange={setInputTab}
        />
        {inputTab === "auto" && (
          <>
            <div style={{ display: "grid", gridTemplateColumns: "repeat(3,1fr)", gap: 10, marginBottom: 12 }}>
              <Input label="Today's Price"     value={price} onChange={setPrice} />
              <Input label="ATR"               value={atr}   onChange={setAtr} />
              <Input label="Yesterday's Close" value={yest}  onChange={setYest} />
            </div>
            {err && <div style={{ ...mono, fontSize: 11, color: C.red, marginBottom: 8 }}>{err}</div>}
            <Btn variant="primary" onClick={runAuto} disabled={loading}>
              {loading ? "Running…" : "↗ Run Algorithm"}
            </Btn>
          </>
        )}
        {inputTab === "manual" && (
          <>
            <div style={{ display: "grid", gridTemplateColumns: "repeat(3,1fr)", gap: 10, marginBottom: 12 }}>
              <Input label="Action"     value={mSide}  onChange={setMSide}  options={["BUY", "SELL", "HOLD"]} type="text" />
              <Input label="Fill Price" value={mPrice} onChange={setMPrice} />
              <Input label="Quantity"   value={mQty}   onChange={setMQty}   step="1" />
            </div>
            {err && <div style={{ ...mono, fontSize: 11, color: C.red, marginBottom: 8 }}>{err}</div>}
            <Btn
              variant={mSide === "BUY" ? "primary" : mSide === "SELL" ? "sell" : "default"}
              onClick={runManual} disabled={loading}>
              {loading ? "Processing…" : `↗ Inject ${mSide}`}
            </Btn>
          </>
        )}
      </Panel>

      <div style={{ borderTop: `1px solid ${C.border}`, margin: "4px 0" }} />

      <TradeLogSection logRows={logRows} onDelete={onDelete} onUndo={onUndo} undoSnapshot={undoSnapshot} />
    </div>
  );
}

// ─── TRADE LOG ────────────────────────────────────────────────────────────────
function TradeLogSection({ logRows, onDelete, onUndo, undoSnapshot }) {
  const [filter,    setFilter]    = useState("ALL");
  const [expanded,  setExpanded]  = useState(null);
  const [deleteRow, setDeleteRow] = useState("2");
  const [loading,   setLoading]   = useState(false);

  useEffect(() => {
    if (logRows.length > 0) setExpanded(null);
  }, [logRows.length]);

  const filtered =
    filter === "ALL"  ? logRows :
    filter === "BUY"  ? logRows.filter(r => (r.action ?? "").includes("BUY")  && !(r.action ?? "").includes("SELL")) :
    filter === "SELL" ? logRows.filter(r => (r.action ?? "").includes("SELL") && !(r.action ?? "").includes("BUY")) :
    logRows.filter(r => (r.action ?? "").includes("HOLD"));

  async function doDelete() {
    const row = parseInt(deleteRow);
    if (isNaN(row) || row < 2) return;

    const targetRow = logRows[row - 1];
    if (!targetRow) {
        alert("Row not found — refresh and try again.");
        return;
    }
    const actualTickNumber = targetRow.tick;

    if (!confirm(`Roll back to row ${row - 1}? Rows ${row}+ will be deleted.`)) return;
    setLoading(true);
    await onDelete(actualTickNumber);
    setLoading(false);
}

  const TH = ({ children, w }) => (
    <th style={{
      padding: "6px 9px", textAlign: "left", color: C.head, whiteSpace: "nowrap",
      borderBottom: `1px solid ${C.border}`, fontSize: 9, textTransform: "uppercase",
      letterSpacing: "0.1em", fontFamily: "system-ui", fontWeight: 600, minWidth: w,
    }}>
      {children}
    </th>
  );
  const TD = ({ children, color }) => (
    <td style={{ padding: "5px 9px", color: color || C.text, fontFamily: "monospace", fontSize: 11, whiteSpace: "nowrap" }}>
      {children ?? "—"}
    </td>
  );

  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
      <div style={{ display: "flex", alignItems: "center", gap: 8, flexWrap: "wrap" }}>
        <span style={{ fontSize: 9, color: C.head, textTransform: "uppercase", letterSpacing: "0.1em", fontFamily: "system-ui" }}>
          Trade Log ({logRows.length})
        </span>
        <div style={{ width: 1, height: 14, background: C.border }} />
        {["ALL", "BUY", "SELL", "HOLD"].map(f => (
          <button key={f} onClick={() => setFilter(f)} style={{
            ...mono, fontSize: 10, padding: "3px 10px", cursor: "pointer", borderRadius: 4,
            background: filter === f ? (f === "BUY" ? C.buyDim : f === "SELL" ? C.sellDim : C.panel) : "transparent",
            border: `1px solid ${filter === f ? C.borderHi : C.border}`,
            color: filter === f ? (f === "BUY" ? C.buy : f === "SELL" ? C.sell : C.text) : C.muted,
          }}>
            {f}{filter === f ? ` (${filtered.length})` : ""}
          </button>
        ))}
        <div style={{ marginLeft: "auto", display: "flex", gap: 6, alignItems: "center" }}>
          {undoSnapshot && (
            <Btn variant="amber" onClick={onUndo} style={{ fontSize: 10, padding: "4px 12px" }}>
              ↩ Undo Rollback
            </Btn>
          )}
          <span style={{ fontSize: 9, color: C.muted, textTransform: "uppercase", letterSpacing: "0.1em", fontFamily: "system-ui" }}>
            Keep up to row
          </span>
          <input type="number" min="2" value={deleteRow}
            onChange={e => setDeleteRow(e.target.value)}
            style={{ ...mono, width: 52, fontSize: 12, background: C.bg, border: `1px solid ${C.border}`, color: C.text, borderRadius: 4, padding: "5px 7px", outline: "none" }}
          />
          <Btn variant="danger" onClick={doDelete} disabled={loading} style={{ fontSize: 10, padding: "5px 12px" }}>
            {loading ? "…" : "↺ Rollback"}
          </Btn>
        </div>
      </div>

      <div style={{ border: `1px solid ${C.border}`, borderRadius: 8, overflow: "hidden" }}>
        <div style={{ overflowX: "auto" }}>
          <table style={{ borderCollapse: "collapse", width: "100%", minWidth: 960 }}>
            <thead>
              <tr style={{ background: C.surface }}>
                <TH w={28}>#</TH>
                <TH w={90}>Date / Time</TH>
                <TH w={60}>Price</TH>
                <TH w={48}>ATR</TH>
                <TH w={65}>Yest Close</TH>
                <TH w={60}>Avg Px</TH>
                <TH w={190}>Action</TH>
                <TH w={140}>Buy Orders</TH>
                <TH w={140}>Sell Orders</TH>
                <TH w={70}>Cash</TH>
                <TH w={50}>Shares</TH>
                <TH w={70}>Equity</TH>
                <TH w={48}>DD%</TH>
                <TH w={80}>Real P&L</TH>
              </tr>
            </thead>
            <tbody>
              {filtered.length === 0 && (
                <tr>
                  <td colSpan={14} style={{ padding: "3rem", textAlign: "center", color: C.muted, fontFamily: "system-ui", fontSize: 12 }}>
                    No trades yet — run a tick to begin
                  </td>
                </tr>
              )}
              {filtered.map((row, i) => {
                const isExp   = expanded === (row.id ?? i);
                const realIdx = logRows.indexOf(row);
                const isBuy   = (row.action ?? "").includes("BUY");
                const isSell  = (row.action ?? "").includes("SELL");
                const dd      = row.drawdown ?? 0;
                const rpl     = row.realizedPL ?? 0;
                return [
                  <tr key={row.id ?? i}
                    onClick={() => setExpanded(isExp ? null : (row.id ?? i))}
                    style={{
                      borderBottom: `1px solid ${C.border}`,
                      background: isExp ? C.surface : i % 2 === 0 ? "transparent" : C.surface + "88",
                      cursor: "pointer",
                      borderLeft: `2px solid ${isBuy ? C.buy : isSell ? C.sell : "transparent"}`,
                    }}
                  >
                    <TD color={C.muted}>{realIdx + 1}</TD>
                    <TD>{row.date} {row.time}</TD>
                    <TD>{row.price}</TD>
                    <TD color={C.muted}>{row.atr}</TD>
                    <TD color={C.muted}>{row.yestClose}</TD>
                    <TD>{row.avgPrice}</TD>
                    <td style={{ padding: "5px 9px" }}><ActionTag action={row.action} /></td>
                    <TD color={C.buy}>{row.buyL1 ? `L1: ${row.buyL1.split(" = ")[0]}` : "—"}</TD>
                    <TD color={C.sell}>{row.sellL1 ? `L1: ${row.sellL1.split(" = ")[0]}` : "—"}</TD>
                    <TD>${r2(row.cash)}</TD>
                    <TD>{row.shares}</TD>
                    <TD>${r2(row.equity)}</TD>
                    <TD color={dd > 15 ? C.red : C.text}>{dd}%</TD>
                    <TD color={rpl > 0 ? C.buy : rpl < 0 ? C.red : C.muted}>
                      {rpl !== 0 ? `$${r2(rpl)}` : "—"}
                    </TD>
                  </tr>,
                  isExp && (
                    <tr key={(row.id ?? i) + "-x"} style={{ background: C.surface + "cc" }}>
                      <td colSpan={14} style={{ padding: "12px 18px", borderBottom: `1px solid ${C.border}` }}>
                        <div style={{ display: "flex", gap: 28, flexWrap: "wrap" }}>
                          <div>
                            <SectionLabel>Buy Ladder</SectionLabel>
                            {[row.buyL1, row.buyL2, row.buyL3].map((l, j) => l
                              ? <div key={j} style={{ ...mono, fontSize: 11, color: C.buy, marginBottom: 3 }}>L{j+1}: {l}</div>
                              : null)}
                            {!row.buyL1 && !row.buyL2 && !row.buyL3 && <span style={{ ...mono, fontSize: 11, color: C.muted }}>—</span>}
                          </div>
                          <div>
                            <SectionLabel>Sell Ladder</SectionLabel>
                            {[row.sellL1, row.sellL2, row.sellL3].map((l, j) => l
                              ? <div key={j} style={{ ...mono, fontSize: 11, color: C.sell, marginBottom: 3 }}>L{j+1}: {l}</div>
                              : null)}
                            {!row.sellL1 && !row.sellL2 && !row.sellL3 && <span style={{ ...mono, fontSize: 11, color: C.muted }}>—</span>}
                          </div>
                          <div>
                            <SectionLabel>Details</SectionLabel>
                            <div style={{ ...mono, fontSize: 11, color: C.muted, lineHeight: 1.9 }}>
                              <div>Bias: <span style={{ color: row.bias === "UP" ? C.buy : row.bias === "DOWN" ? C.sell : C.muted }}>{row.bias ?? "—"}</span></div>
                              <div>Momentum score: <span style={{ color: C.amber }}>{r2(row.momentumScore)}</span></div>
                              <div>Unrealized P&L: <span style={{ color: (row.unrealizedPL ?? 0) >= 0 ? C.buy : C.red }}>${r2(row.unrealizedPL)}</span></div>
                              <div>Cost basis: ${r2((row.avgPrice ?? 0) * (row.shares ?? 0))}</div>
                              <div>ATR %: {row.price ? r2((row.atr / row.price) * 100) : "—"}%</div>
                              <div>Peak equity: ${r2(row.peakEquity)}</div>
                              <div>Type: <span style={{ color: row.tradeType === "manual" ? C.amber : C.blue }}>{row.tradeType ?? "auto"}</span></div>
                            </div>
                          </div>
                        </div>
                      </td>
                    </tr>
                  ),
                ];
              })}
            </tbody>
          </table>
        </div>
      </div>

      {undoSnapshot
        ? <div style={{ ...mono, fontSize: 10, color: C.amber }}>
            ↩ Undo available — restores display only. DB was rolled back; re-run ticks to rebuild if needed.
          </div>
        : <div style={{ fontSize: 9, color: C.muted, fontFamily: "system-ui" }}>
            Click any row to expand · Confirm dialog shown before rollback
          </div>
      }
    </div>
  );
}

// ─── ANALYTICS ────────────────────────────────────────────────────────────────
function AnalyticsPage({ logRows }) {
  if (!logRows.length) return (
    <div style={{ padding: "5rem", textAlign: "center", color: C.muted, fontFamily: "system-ui", fontSize: 13 }}>
      No data yet — run some ticks to see analytics
    </div>
  );

  const cd = logRows.map((r, i) => ({
    i: i + 1, equity: r.equity, dd: r.drawdown,
    price: r.price, avgPx: r.avgPrice, realPL: r.realizedPL,
  }));

  const buys      = logRows.filter(r => (r.action ?? "").includes("BUY")).length;
  const sells     = logRows.filter(r => (r.action ?? "").includes("SELL")).length;
  const holds     = logRows.filter(r => (r.action ?? "").includes("HOLD")).length;
  const totalReal = r2(logRows.reduce((a, r) => a + (r.realizedPL || 0), 0));
  const maxDD     = r2(Math.max(...logRows.map(r => r.drawdown ?? 0)));
  const firstEq   = logRows[0].equity ?? 0;
  const lastEq    = logRows[logRows.length - 1].equity ?? 0;
  const totalRet  = r2((lastEq - firstEq) / (firstEq || 1) * 100);
  const wins      = logRows.filter(r => (r.realizedPL ?? 0) > 0).length;
  const losses    = logRows.filter(r => (r.realizedPL ?? 0) < 0).length;
  const winRate   = wins + losses > 0 ? r2(wins / (wins + losses) * 100) : 0;

  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 10 }}>
      <div style={{ display: "grid", gridTemplateColumns: "repeat(4,1fr)", gap: 8 }}>
        <Metric label="Total Return"  value={`${totalRet}%`}  color={totalRet >= 0 ? C.buy : C.red} />
        <Metric label="Realized P&L" value={`$${totalReal}`} color={totalReal >= 0 ? C.buy : C.red} />
        <Metric label="Max Drawdown" value={`${maxDD}%`}     danger={maxDD > 15} />
        <Metric label="Win Rate"     value={`${winRate}%`}   sub={`${wins}W / ${losses}L`} color={C.blue} />
      </div>
      <div style={{ display: "grid", gridTemplateColumns: "repeat(3,1fr)", gap: 8 }}>
        <Metric label="Total Ticks"  value={logRows.length} sub={`${buys} buy · ${sells} sell · ${holds} hold`} />
        <Metric label="Start Equity" value={`$${firstEq}`} />
        <Metric label="End Equity"   value={`$${lastEq}`}  color={lastEq >= firstEq ? C.buy : C.red} />
      </div>
      <Panel>
        <SectionLabel>Equity Curve</SectionLabel>
        <ResponsiveContainer width="100%" height={160}>
          <AreaChart data={cd} margin={{ top: 4, right: 4, left: 0, bottom: 0 }}>
            <defs>
              <linearGradient id="geq" x1="0" y1="0" x2="0" y2="1">
                <stop offset="5%"  stopColor={C.buy} stopOpacity={0.18} />
                <stop offset="95%" stopColor={C.buy} stopOpacity={0} />
              </linearGradient>
            </defs>
            <XAxis dataKey="i" tick={{ fontSize: 9, fill: C.muted, fontFamily: "monospace" }} />
            <YAxis width={56} tick={{ fontSize: 9, fill: C.muted, fontFamily: "monospace" }} tickFormatter={v => `$${v}`} />
            <Tooltip content={<ChartTip />} />
            <Area type="monotone" dataKey="equity" stroke={C.buy} strokeWidth={1.5} fill="url(#geq)" name="Equity" dot={false} />
          </AreaChart>
        </ResponsiveContainer>
      </Panel>
      <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 10 }}>
        <Panel>
          <SectionLabel>Price vs Avg Cost Basis</SectionLabel>
          <ResponsiveContainer width="100%" height={140}>
            <LineChart data={cd} margin={{ top: 4, right: 4, left: 0, bottom: 0 }}>
              <XAxis dataKey="i" tick={{ fontSize: 9, fill: C.muted, fontFamily: "monospace" }} />
              <YAxis width={40} tick={{ fontSize: 9, fill: C.muted, fontFamily: "monospace" }} />
              <Tooltip content={<ChartTip />} />
              <Line type="monotone" dataKey="price" stroke={C.blue}  strokeWidth={1.5} dot={false} name="Price" />
              <Line type="monotone" dataKey="avgPx" stroke={C.amber} strokeWidth={1} strokeDasharray="4 3" dot={false} name="Avg Cost" />
            </LineChart>
          </ResponsiveContainer>
        </Panel>
        <Panel>
          <SectionLabel>Drawdown %</SectionLabel>
          <ResponsiveContainer width="100%" height={140}>
            <AreaChart data={cd} margin={{ top: 4, right: 4, left: 0, bottom: 0 }}>
              <defs>
                <linearGradient id="gdd" x1="0" y1="0" x2="0" y2="1">
                  <stop offset="5%"  stopColor={C.red} stopOpacity={0.22} />
                  <stop offset="95%" stopColor={C.red} stopOpacity={0} />
                </linearGradient>
              </defs>
              <XAxis dataKey="i" tick={{ fontSize: 9, fill: C.muted, fontFamily: "monospace" }} />
              <YAxis width={36} tick={{ fontSize: 9, fill: C.muted, fontFamily: "monospace" }} tickFormatter={v => `${v}%`} />
              <ReferenceLine y={25} stroke={C.red} strokeDasharray="3 3" strokeWidth={0.8} />
              <Tooltip content={<ChartTip />} />
              <Area type="monotone" dataKey="dd" stroke={C.red} strokeWidth={1.5} fill="url(#gdd)" name="Drawdown %" dot={false} />
            </AreaChart>
          </ResponsiveContainer>
        </Panel>
      </div>
      {logRows.some(r => (r.realizedPL ?? 0) !== 0) && (
        <Panel>
          <SectionLabel>Realized P&L per Trade</SectionLabel>
          <ResponsiveContainer width="100%" height={120}>
            <BarChart data={cd.filter(d => d.realPL !== 0)} margin={{ top: 4, right: 4, left: 0, bottom: 0 }}>
              <XAxis dataKey="i" tick={{ fontSize: 9, fill: C.muted, fontFamily: "monospace" }} />
              <YAxis width={52} tick={{ fontSize: 9, fill: C.muted, fontFamily: "monospace" }} tickFormatter={v => `$${v}`} />
              <ReferenceLine y={0} stroke={C.border} />
              <Tooltip content={<ChartTip />} />
              <Bar dataKey="realPL" name="Realized P&L" radius={[2, 2, 0, 0]}>
                {cd.filter(d => d.realPL !== 0).map((e, i) => (
                  <Cell key={i} fill={e.realPL >= 0 ? C.buy : C.red} fillOpacity={0.85} />
                ))}
              </Bar>
            </BarChart>
          </ResponsiveContainer>
        </Panel>
      )}
    </div>
  );
}

// ─── SETTINGS ─────────────────────────────────────────────────────────────────
function SettingsPage({ session, onSaved }) {
  const cfg = normSettings(session?.settings);

  const [s, setS] = useState({
    ticker:              cfg.ticker,
    initial_shares:      cfg.initialShares,
    initial_cash:        cfg.initialCash,
    trade_fee:           cfg.tradeFee,
    min_fee_cover:       cfg.minFeeCover,
    min_move_atr_mult:   cfg.minMoveAtrMult,
    atr_levels:          cfg.atrLevels,
    base_fractions:      cfg.baseFractions,
    max_drawdown:        cfg.maxDrawdown * 100,   // store as % for display
    momentum_lookback:   cfg.momentumLookback,
    strong_mom_lookback: cfg.strongMomLookback,
    min_qty:             cfg.minQty,
    min_buy_dip_pct:     cfg.minBuyDipPct,
    max_ticks:           cfg.maxTicks,
  });

  // Re-sync if session prop changes (e.g. after reset)
  useEffect(() => {
    const c = normSettings(session?.settings);
    setS({
      ticker:              c.ticker,
      initial_shares:      c.initialShares,
      initial_cash:        c.initialCash,
      trade_fee:           c.tradeFee,
      min_fee_cover:       c.minFeeCover,
      min_move_atr_mult:   c.minMoveAtrMult,
      atr_levels:          c.atrLevels,
      base_fractions:      c.baseFractions,
      max_drawdown:        c.maxDrawdown * 100,
      momentum_lookback:   c.momentumLookback,
      strong_mom_lookback: c.strongMomLookback,
      min_qty:             c.minQty,
      min_buy_dip_pct:     c.minBuyDipPct,
      max_ticks:           c.maxTicks,
    });
  }, [session?.id]);

  const [saved,   setSaved]   = useState(false);
  const [loading, setLoading] = useState(false);

  const set    = (k, v) => setS(p => ({ ...p, [k]: v }));
  const setArr = (k, i, v) => setS(p => { const a = [...p[k]]; a[i] = v; return { ...p, [k]: a }; });

  async function save() {
    setLoading(true);
    // Send snake_case to backend, convert max_drawdown back to decimal
    const res = await apiCall("PUT", "/api/trading/settings", {
      ...s,
      max_drawdown: s.max_drawdown / 100,
    });
    setLoading(false);
    if (!res.error) {
      setSaved(true);
      setTimeout(() => setSaved(false), 2500);
      onSaved(res.session);
    }
  }

  const inp = { fontFamily: "monospace", fontSize: 13, background: C.bg, border: `1px solid ${C.border}`, color: C.text, borderRadius: 4, padding: "7px 10px", width: "100%", outline: "none" };
  const lbl = { fontSize: 9, color: C.muted, textTransform: "uppercase", letterSpacing: "0.1em", marginBottom: 5, fontFamily: "system-ui" };

  const fractionSum = s.base_fractions.reduce((a, v) => a + (parseFloat(v) || 0), 0);

  return (
    <div style={{ display: "flex", flexDirection: "column", gap: 12, maxWidth: 660 }}>
      <Panel>
        <SectionLabel>Identity & Fees</SectionLabel>
        <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 10, marginBottom: 16 }}>
          <div><div style={lbl}>Ticker</div>
            <input type="text"   value={s.ticker}             onChange={e => set("ticker", e.target.value)}             style={inp} /></div>
          <div><div style={lbl}>Initial Shares</div>
            <input type="number" value={s.initial_shares}     onChange={e => set("initial_shares",     +e.target.value)} style={inp} /></div>
          <div><div style={lbl}>Initial Cash ($)</div>
            <input type="number" value={s.initial_cash}       onChange={e => set("initial_cash",       +e.target.value)} style={inp} /></div>
          <div><div style={lbl}>Trade Fee ($)</div>
            <input type="number" step="0.5" value={s.trade_fee}       onChange={e => set("trade_fee",        +e.target.value)} style={inp} /></div>
          <div><div style={lbl}>Min Fee Cover ($)</div>
            <input type="number" step="0.5" value={s.min_fee_cover}   onChange={e => set("min_fee_cover",    +e.target.value)} style={inp} /></div>
          <div><div style={lbl}>Min Move ATR Mult</div>
            <input type="number" step="0.01" value={s.min_move_atr_mult} onChange={e => set("min_move_atr_mult", +e.target.value)} style={inp} /></div>
        </div>

        <SectionLabel>Algorithm Parameters</SectionLabel>
        <div style={{ display: "grid", gridTemplateColumns: "repeat(3,1fr)", gap: 10, marginBottom: 16 }}>
          <div><div style={lbl}>Max Drawdown Stop (%)</div>
            <input type="number" step="1"    value={s.max_drawdown}        onChange={e => set("max_drawdown",        +e.target.value)} style={inp} /></div>
          <div><div style={lbl}>Momentum Lookback</div>
            <input type="number"             value={s.momentum_lookback}   onChange={e => set("momentum_lookback",   +e.target.value)} style={inp} /></div>
          <div><div style={lbl}>Strong Mom Lookback</div>
            <input type="number"             value={s.strong_mom_lookback} onChange={e => set("strong_mom_lookback", +e.target.value)} style={inp} /></div>
          <div><div style={lbl}>Min Qty</div>
            <input type="number"             value={s.min_qty}             onChange={e => set("min_qty",             +e.target.value)} style={inp} /></div>
          <div><div style={lbl}>Min Buy Dip %</div>
            <input type="number" step="0.01" value={s.min_buy_dip_pct}    onChange={e => set("min_buy_dip_pct",    +e.target.value)} style={inp} /></div>
          <div><div style={lbl}>Max Ticks in Memory</div>
            <input type="number"             value={s.max_ticks}           onChange={e => set("max_ticks",           +e.target.value)} style={inp} /></div>
        </div>

        <SectionLabel>ATR Ladder Multipliers</SectionLabel>
        <div style={{ display: "grid", gridTemplateColumns: "repeat(3,1fr)", gap: 8, marginBottom: 16 }}>
          {[0, 1, 2].map(i => (
            <div key={i}><div style={lbl}>Level {i + 1}</div>
              <input type="number" step="0.1" value={s.atr_levels[i]} onChange={e => setArr("atr_levels", i, +e.target.value)} style={inp} />
            </div>
          ))}
        </div>

        <SectionLabel>Ladder Size Fractions (sum ≈ 1)</SectionLabel>
        <div style={{ display: "grid", gridTemplateColumns: "repeat(3,1fr)", gap: 8, marginBottom: 10 }}>
          {[0, 1, 2].map(i => (
            <div key={i}><div style={lbl}>Level {i + 1}</div>
              <input type="number" step="0.05" min="0" max="1" value={s.base_fractions[i]} onChange={e => setArr("base_fractions", i, +e.target.value)} style={inp} />
            </div>
          ))}
        </div>
        <div style={{ fontSize: 9, color: C.muted, marginBottom: 16, fontFamily: "system-ui" }}>
          Sum: {r2(fractionSum)}
          {Math.abs(fractionSum - 1) > 0.01 &&
            <span style={{ color: C.red, marginLeft: 8 }}>⚠ should sum to 1.0</span>}
        </div>

        <div style={{ display: "flex", alignItems: "center", gap: 10 }}>
          <Btn variant="primary" onClick={save} disabled={loading}>
            {loading ? "Saving…" : "↗ Save Settings"}
          </Btn>
          {saved && <span style={{ ...mono, fontSize: 11, color: C.buy }}>✓ Saved</span>}
        </div>
      </Panel>
    </div>
  );
}

// ─── ROOT ─────────────────────────────────────────────────────────────────────
const NAV = [
  { id: "dashboard", label: "DASHBOARD" },
  { id: "analytics", label: "ANALYTICS" },
  { id: "settings",  label: "SETTINGS"  },
];

export default function Dashboard({ session: initSession, logRows: initLogRows }) {
  const [session,      setSession]      = useState(initSession  ?? {});
  const [logRows,      setLogRows]      = useState(initLogRows  ?? []);
  const [page,         setPage]         = useState("dashboard");
  const [toast,        setToast]        = useState("");
  const [resetting,    setResetting]    = useState(false);
  const [undoSnapshot, setUndoSnapshot] = useState(null);

  const st  = session?.state    ?? {};
  const cfg = normSettings(session?.settings);

  function showToast(msg) {
    setToast(msg ?? "");
    setTimeout(() => setToast(""), 3000);
  }

  const handleRefresh = useCallback((newSession, newLogRow) => {
    if (newSession) setSession(newSession);
    if (newLogRow)  setLogRows(prev => [...prev, newLogRow]);
    showToast(newLogRow?.action ?? "Tick processed");
  }, []);

const handleDelete = useCallback(async (rowNum) => {
    console.log('[DELETE] firing with row_number:', rowNum);
    console.log('[DELETE] current session state:', JSON.stringify(session));
    console.log('[DELETE] current logRows count:', logRows.length);

    setUndoSnapshot({ rows: logRows, session });
    const res = await apiCall("POST", "/api/trading/delete-tick", { row_number: rowNum });

    console.log('[DELETE] response:', JSON.stringify(res));

    if (!res.error) {
        setSession(res.session ?? session);
        setLogRows(res.logRows ?? []);
        showToast(`↺ Kept up to row ${rowNum - 1}, deleted row ${rowNum}+`);
    } else {
        setUndoSnapshot(null);
        showToast("Rollback failed");
    }
}, [logRows, session]);

  function handleUndo() {
    if (!undoSnapshot) return;
    setLogRows(undoSnapshot.rows);
    setSession(undoSnapshot.session);
    setUndoSnapshot(null);
    showToast("↩ Undo done (display restored — DB still rolled back)");
  }

  async function handleReset() {
    if (!confirm("Reset ALL trade history and algorithm state? This cannot be undone.")) return;
    setResetting(true);
    const res = await apiCall("POST", "/api/trading/reset", {});
    setResetting(false);
    setSession(res.session ?? {});
    setLogRows(res.logRows ?? []);
    setUndoSnapshot(null);
    showToast("Full reset complete");
  }

  return (
    <AuthenticatedLayout>
      <div style={{ minHeight: "100vh", background: C.bg, color: C.text }}>
        <style>{`
          * { box-sizing: border-box; margin: 0; padding: 0; }
          body { background: ${C.bg}; }
          @import url('https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;500;600&display=swap');
          input, select, button { transition: border-color 0.15s, background 0.15s; }
          input:focus, select:focus { border-color: ${C.borderHi} !important; outline: none; }
          ::-webkit-scrollbar { width: 5px; height: 5px; }
          ::-webkit-scrollbar-track { background: ${C.surface}; }
          ::-webkit-scrollbar-thumb { background: ${C.border}; border-radius: 3px; }
        `}</style>

        {/* ── Nav bar ── */}
        <div style={{
          borderBottom: `1px solid ${C.border}`, padding: "0 24px",
          display: "flex", alignItems: "center", height: 52,
          background: C.surface, position: "sticky", top: 0, zIndex: 100,
        }}>
          <div style={{ display: "flex", alignItems: "center", gap: 10, marginRight: 28 }}>
            <div style={{
              width: 28, height: 28, borderRadius: 6,
              background: `linear-gradient(135deg, ${C.buy}33, ${C.buy}11)`,
              border: `1px solid ${C.buy}55`,
              display: "flex", alignItems: "center", justifyContent: "center",
              ...mono, fontSize: 11, color: C.buy, fontWeight: 700,
            }}>L</div>
            <div>
              <div style={{ ...mono, fontSize: 12, fontWeight: 600, color: C.text, letterSpacing: "0.08em" }}>
                {cfg.ticker ?? "—"}
                <span style={{ color: C.muted, fontWeight: 400, marginLeft: 8 }}>Ladder Trading</span>
              </div>
              <div style={{ fontSize: 9, color: C.muted, fontFamily: "system-ui", letterSpacing: "0.06em", marginTop: 1 }}>
                {logRows.length} ticks · {st.shares ?? 0} sh · ${r2(st.cash)} cash
              </div>
            </div>
          </div>

          <nav style={{ display: "flex", gap: 2 }}>
            {NAV.map(n => (
              <button key={n.id} onClick={() => setPage(n.id)} style={{
                ...mono, fontSize: 10, padding: "4px 14px", cursor: "pointer", borderRadius: 4,
                background: page === n.id ? C.buy + "15" : "transparent",
                border: `1px solid ${page === n.id ? C.buy + "44" : "transparent"}`,
                color: page === n.id ? C.buy : C.muted, letterSpacing: "0.1em",
              }}>
                {n.label}
              </button>
            ))}
          </nav>

          <Btn variant="danger" onClick={handleReset} disabled={resetting}
            style={{ marginLeft: "auto", fontSize: 10, padding: "4px 12px" }}>
            {resetting ? "…" : "↺ Reset"}
          </Btn>
        </div>

        <div style={{ maxWidth: 1320, margin: "0 auto", padding: "20px 24px" }}>
          {page === "dashboard" && (
            <DashboardPage
              session={session}
              logRows={logRows}
              onRefresh={handleRefresh}
              onDelete={handleDelete}
              onUndo={handleUndo}
              undoSnapshot={undoSnapshot}
            />
          )}
          {page === "analytics" && <AnalyticsPage logRows={logRows} />}
          {page === "settings"  && <SettingsPage  session={session} onSaved={setSession} />}
        </div>

        <Toast msg={toast} />
      </div>
    </AuthenticatedLayout>
  );
}
