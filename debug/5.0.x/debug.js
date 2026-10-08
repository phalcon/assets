/**
 * This file is part of the Phalcon Framework.
 *
 * (c) Phalcon Team <team@phalcon.io>
 *
 * For the full copyright and license information, please view the LICENSE.txt
 * file that was distributed with this source code.
 *
 * Phalcon\Support\Debug — debug error page behavior.
 * Served from the assets repository (e.g. assets.phalcon.io/debug/<version>/debug.js).
 *
 * This is an enhancer: the component renders the full markup server-side, this
 * script only adds behavior. Markup contract (classes / data-attributes the
 * component must emit) is documented at the bottom of this file.
 */
(function () {
    "use strict";

    var THEME_KEY = "phalcon-debug-theme";

    function escHtml(s) {
        return String(s).replace(/[&<>]/g, function (c) {
            return { "&": "&amp;", "<": "&lt;", ">": "&gt;" }[c];
        });
    }

    function escAttr(s) {
        return String(s).replace(/"/g, "&quot;");
    }

    /* ---- PHP syntax highlighter (no dependencies) ---- */
    var RULES = [
        ["c", /^(?:\/\/[^\n]*|#[^\n]*|\/\*[\s\S]*?\*\/)/],
        ["s", /^(?:"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*')/],
        ["v", /^\$[A-Za-z_]\w*/],
        ["n", /^\d+\.?\d*/],
        ["k", /^(?:abstract|array|as|break|callable|case|catch|class|clone|const|continue|declare|default|do|echo|elseif|else|enum|extends|finally|final|fn|foreach|for|function|global|goto|implements|include_once|include|instanceof|insteadof|interface|isset|list|match|namespace|new|print|private|protected|public|readonly|require_once|require|return|static|switch|throw|trait|try|unset|use|var|while|yield|true|false|null|void|int|string|bool|float|mixed|object|self|parent)\b/],
        ["f", /^[A-Za-z_]\w*(?=\s*\()/],
        ["t", /^[A-Z]\w*/],
        ["i", /^[A-Za-z_]\w*/],
        ["w", /^\s+/],
        ["o", /^[^\w\s$]+/]
    ];

    function highlightPhp(src) {
        var out = "", i = 0;
        while (i < src.length) {
            var rest = src.slice(i), matched = false;
            for (var r = 0; r < RULES.length; r++) {
                var cls = RULES[r][0], m = rest.match(RULES[r][1]);
                if (m) {
                    var txt = m[0];
                    out += (cls === "w") ? escHtml(txt) : '<span class="t-' + cls + '">' + escHtml(txt) + "</span>";
                    i += txt.length;
                    matched = true;
                    break;
                }
            }
            if (!matched) { out += escHtml(src[i]); i++; }
        }
        return out;
    }

    function highlightAll() {
        var cells = document.querySelectorAll(".code td.src");
        for (var i = 0; i < cells.length; i++) {
            var cell = cells[i];
            if (cell.getAttribute("data-hl")) { continue; }
            var text = cell.textContent;
            if (text.replace(/ /g, " ").trim() !== "") {
                cell.innerHTML = highlightPhp(text);
            }
            cell.setAttribute("data-hl", "1");
        }
    }

    /* ---- toast ---- */
    var toastEl = null, toastTimer = null;
    function toast(msg) {
        if (!toastEl) {
            toastEl = document.createElement("div");
            toastEl.className = "phalcon-debug-toast";
            document.body.appendChild(toastEl);
        }
        toastEl.textContent = msg;
        toastEl.classList.add("show");
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.classList.remove("show"); }, 1400);
    }

    function copy(text, msg) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () { toast(msg); });
        }
    }

    /* ---- theme ---- */
    function applySavedTheme() {
        var saved;
        try { saved = localStorage.getItem(THEME_KEY); } catch (e) {}
        if (saved) { document.documentElement.setAttribute("data-theme", saved); }
    }
    function toggleTheme() {
        var root = document.documentElement;
        var next = root.getAttribute("data-theme") === "dark" ? "light" : "dark";
        root.setAttribute("data-theme", next);
        try { localStorage.setItem(THEME_KEY, next); } catch (e) {}
    }

    /* ---- tabs ---- */
    function selectTab(name) {
        var tabs = document.querySelectorAll(".tab");
        var panels = document.querySelectorAll(".panel");
        for (var i = 0; i < tabs.length; i++) {
            tabs[i].classList.toggle("is-active", tabs[i].getAttribute("data-tab") === name);
        }
        for (var j = 0; j < panels.length; j++) {
            panels[j].classList.toggle("is-active", panels[j].id === name);
        }
    }

    /* ---- per-frame action buttons, injected from data-file / data-line ---- */
    function injectFrameActions() {
        var files = document.querySelectorAll(".frame-file[data-file]");
        for (var i = 0; i < files.length; i++) {
            var el = files[i];
            if (el.querySelector(".mini")) { continue; }
            var file = el.getAttribute("data-file");
            var line = el.getAttribute("data-line") || "";
            var enc = encodeURI(file);
            var mini = document.createElement("span");
            mini.className = "mini";
            mini.innerHTML =
                '<button class="icon-btn" data-copy="' + escAttr(file) + '">Copy path</button>' +
                '<a class="icon-btn" href="phpstorm://open?file=' + enc + "&line=" + line + '">PhpStorm</a>' +
                '<a class="icon-btn" href="vscode://file' + enc + (line ? ":" + line : "") + '">VS Code</a>';
            el.appendChild(mini);
        }
    }

    /* ---- build a plain-text stack trace from the DOM ---- */
    function buildTrace() {
        var out = [];
        var frames = document.querySelectorAll(".frame");
        for (var i = 0; i < frames.length; i++) {
            var f = frames[i];
            var num = text(f.querySelector(".frame-num"));
            var call = text(f.querySelector(".frame-call")).replace(/\s+/g, " ").trim();
            var fileEl = f.querySelector(".frame-file");
            var loc = "";
            if (fileEl) {
                var file = fileEl.getAttribute("data-file") || text(fileEl.querySelector(".path"));
                var line = fileEl.getAttribute("data-line");
                loc = file + (line ? ":" + line : "");
            }
            out.push((num || "#" + i) + " " + call + (loc ? "  " + loc.trim() : ""));
        }
        return out.join("\n");
    }

    function text(el) { return el ? el.textContent : ""; }

    /* ---- wiring ---- */
    function onClick(e) {
        var copyEl = e.target.closest("[data-copy]");
        if (copyEl) {
            e.preventDefault();
            copy(copyEl.getAttribute("data-copy"), "Path copied");
            return;
        }

        var tab = e.target.closest(".tab[data-tab]");
        if (tab) {
            selectTab(tab.getAttribute("data-tab"));
            return;
        }

        var action = e.target.closest("[data-action]");
        if (!action) { return; }
        var name = action.getAttribute("data-action");

        if (name === "toggle-theme") {
            toggleTheme();
        } else if (name === "copy-trace") {
            copy(buildTrace(), "Stack trace copied");
        } else if (name === "expand-all" || name === "collapse-all") {
            var open = name === "expand-all";
            var frames = document.querySelectorAll(".frame");
            for (var i = 0; i < frames.length; i++) { frames[i].open = open; }
        }
    }

    function init() {
        applySavedTheme();
        highlightAll();
        injectFrameActions();
        document.addEventListener("click", onClick);
    }

    applySavedTheme(); // apply early to reduce flash
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
})();

/**
 * Markup contract — what the component must render for this script:
 *
 *   <html data-theme="light">                        theme root (script flips data-theme)
 *
 *   Tabs:     <button class="tab" data-tab="NAME">    paired with
 *             <div class="panel" id="NAME">           (first tab/panel gets class="is-active")
 *
 *   Chrome:   <button data-action="toggle-theme">
 *             <button data-action="copy-trace">
 *             <button data-action="expand-all">
 *             <button data-action="collapse-all">
 *
 *   Frames:   <details class="frame [app|vendor]" [open]>
 *               <summary><div class="frame-head">
 *                 <span class="frame-num">#0</span>
 *                 <span class="frame-call"> ...signature... <span class="chev">&#9656;</span></span>
 *               </div></summary>
 *               <div class="frame-file" data-file="/abs/path.php" data-line="87">
 *                 <span class="path"><b>/abs/path.php</b> : 87</span>
 *               </div>                                 (Copy/PhpStorm/VS Code buttons injected here)
 *               <div class="code"><table>
 *                 <tr [class="hl"]><td class="ln">87</td><td class="src">RAW SOURCE LINE</td></tr>
 *               </table></div>
 *             </details>
 *
 *   Tables:   <table class="grid"><tr><td class="k">Key</td><td class="v">Value</td></tr></table>
 *
 *   The <td class="src"> cells must contain the raw (entity-escaped) source line;
 *   this script tokenizes and colorizes them on load.
 */
