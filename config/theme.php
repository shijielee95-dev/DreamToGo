<?php
/**
 * config/theme.php
 * ─────────────────────────────────────────────
 * ALL design tokens live here.
 * Change this file → changes the whole app.
 * ─────────────────────────────────────────────
 */

return [

    /* ── Sidebar ── */
    'sidebar_bg'          => 'bg-[#0f172a]',
    'sidebar_border'      => 'border-[#1e293b]',
    'sidebar_text'        => 'text-slate-400',
    'sidebar_active'      => 'bg-indigo-600 text-white',
    'sidebar_hover'       => 'hover:bg-white/5 hover:text-slate-200',
    'sidebar_label'       => 'text-slate-500',
    'sidebar_logo_bg'     => 'bg-gradient-to-br from-indigo-500 to-violet-600',
    'sidebar_width'       => 'w-56',

    /* ── Header ── */
    'header_bg'           => 'bg-white',
    'header_border'       => 'border-slate-200',
    'header_height'       => 'h-14',

    /* ── Page body ── */
    'body_bg'             => 'bg-slate-100',

    /* ── Buttons ── */
    'btn_primary'         => 'bg-indigo-600 hover:bg-indigo-700 text-white',
    'btn_secondary'       => 'bg-white hover:bg-slate-50 text-slate-700 border border-slate-200',
    'btn_danger'          => 'bg-red-600 hover:bg-red-700 text-white',
    'btn_ghost'           => 'bg-slate-100 hover:bg-slate-200 text-slate-600',
    'btn_base'            => 'inline-flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-colors',

    /* ── Inputs ── */
    'input'               => 'w-full h-9 border border-slate-300 rounded-lg px-3 text-sm font-normal text-black bg-white focus:outline-none focus:border-indigo-500 transition',
    'label'               => 'block text-sm font-normal text-black mb-1',
    'select'              => 'h-9 border border-slate-300 rounded-lg px-3 text-sm font-normal text-black bg-white focus:outline-none focus:border-indigo-500 transition',

    /* ── Cards ── */
    'card'                => 'bg-white rounded-xl border border-slate-200 p-5',
    'card_title'          => 'text-xs font-semibold text-slate-500 uppercase tracking-wide pb-3 mb-4 border-b border-slate-100',

    /* ── Tables ── */
    'table_wrap'          => 'bg-white rounded-xl border border-slate-200 overflow-hidden',
    'th'                  => 'px-4 py-2.5 text-left text-[10px] font-semibold text-slate-500 uppercase tracking-wide bg-slate-50',
    'td'                  => 'px-4 py-3 text-sm font-medium text-slate-900 border-b border-slate-100',

    /* ── Status badges ── */
    'badge' => [
        'draft'     => 'bg-slate-100 text-slate-600',
        'sent'      => 'bg-blue-50 text-blue-700',
        'paid'      => 'bg-green-50 text-green-700',
        'overdue'   => 'bg-red-50 text-red-700',
        'cancelled' => 'bg-slate-100 text-slate-500',
        // LHDN statuses
        'pending'   => 'bg-amber-50 text-amber-700',
        'valid'     => 'bg-green-50 text-green-700',
        'invalid'   => 'bg-red-50 text-red-700',
        'default'   => 'bg-slate-100 text-slate-600',
    ],

    /* ── LHDN accent ── */
    'lhdn_section'        => 'bg-violet-50 border border-violet-200 rounded-xl p-5',
    'lhdn_title'          => 'text-xs font-semibold text-violet-700 uppercase tracking-wide mb-4',

    /* ── Toast ── */
    'toast_success'       => 'bg-green-500',
    'toast_error'         => 'bg-red-500',
    'toast_warning'       => 'bg-amber-500',
    'toast_info'          => 'bg-blue-500',

    /* ── App info ── */
    'app_name'            => 'e-Invoice Portal',
    'app_version'         => 'v2.0',

];
