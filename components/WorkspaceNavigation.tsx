"use client";

import Link from "next/link";
import { useEffect, useMemo, useState } from "react";
import type { NavigationWorkspace } from "@/lib/data/control-plane-repository";

export function WorkspaceNavigation({ workspaces, active }: { workspaces: NavigationWorkspace[]; active: string }) {
  const activeWorkspace = workspaces.find((workspace) => workspace.folders.some((folder) => folder.items.some((item) => item.key === active)))?.key;
  const [expanded, setExpanded] = useState(activeWorkspace ?? workspaces[0]?.key ?? "");
  const [query, setQuery] = useState("");
  const [favourites, setFavourites] = useState<string[]>([]);

  useEffect(() => {
    let stored: string[] = [];
    try { stored = JSON.parse(localStorage.getItem("vat-msa-navigation-favourites") ?? "[]"); } catch { stored = []; }
    queueMicrotask(() => setFavourites(stored));
  }, []);

  const toggleFavourite = (key: string) => {
    const next = favourites.includes(key) ? favourites.filter((item) => item !== key) : [...favourites, key].slice(-8);
    setFavourites(next);
    localStorage.setItem("vat-msa-navigation-favourites", JSON.stringify(next));
  };
  const normalizedQuery = query.trim().toLowerCase();
  const filtered = useMemo(() => workspaces.map((workspace) => ({
    ...workspace,
    folders: workspace.folders.map((folder) => ({
      ...folder,
      items: folder.items.filter((item) => !normalizedQuery || item.label.toLowerCase().includes(normalizedQuery) || workspace.label.toLowerCase().includes(normalizedQuery)),
    })).filter((folder) => folder.items.length),
  })).filter((workspace) => workspace.folders.length), [workspaces, normalizedQuery]);

  return <nav className="workspace-nav" aria-label="Effective organisation workspace">
    <label className="nav-search-label" htmlFor="workspace-nav-search">Find workspace</label>
    <input id="workspace-nav-search" className="nav-search" value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search navigation" />
    {favourites.length && !normalizedQuery ? <div className="nav-favourites" aria-label="Favourite destinations">
      <div className="nav-label">Favourites</div>
      {workspaces.flatMap((workspace) => workspace.folders.flatMap((folder) => folder.items)).filter((item) => favourites.includes(item.key)).map((item) =>
        <Link className={`nav-link ${active === item.key ? "active" : ""}`} href={item.href} key={`favourite-${item.key}`}><span aria-hidden="true">★</span>{item.label}</Link>)}
    </div> : null}
    <div className="nav-label">Licensed workspace</div>
    {filtered.map((workspace) => {
      const isExpanded = normalizedQuery.length > 0 || expanded === workspace.key;
      return <section className="workspace-group" key={workspace.id}>
        <button className={`workspace-trigger ${isExpanded ? "expanded" : ""}`} type="button" onClick={() => setExpanded(isExpanded ? "" : workspace.key)} aria-expanded={isExpanded}>
          <span>{workspace.label}</span><span aria-hidden="true">{isExpanded ? "−" : "+"}</span>
        </button>
        {isExpanded ? <div className="workspace-tree">
          {workspace.folders.map((folder) => <div key={folder.id}>
            <div className="folder-label">{folder.label}</div>
            {folder.items.map((item) => <div className="nav-item-row" key={item.id}>
              <Link className={`nav-link ${active === item.key ? "active" : ""}`} href={item.href} aria-current={active === item.key ? "page" : undefined}>
                <span className="nav-dot" aria-hidden="true" />{item.label}
              </Link>
              <button className="favourite-toggle" type="button" onClick={() => toggleFavourite(item.key)} aria-label={`${favourites.includes(item.key) ? "Remove" : "Add"} ${item.label} ${favourites.includes(item.key) ? "from" : "to"} favourites`}>
                {favourites.includes(item.key) ? "★" : "☆"}
              </button>
            </div>)}
          </div>)}
        </div> : null}
      </section>;
    })}
    {!filtered.length ? <div className="nav-empty">No authorised destination matches.</div> : null}
  </nav>;
}
