-- =============================================================================
-- 001_baseline.sql
-- Phase 2 — Normalisation DB (hackwithoutbarriers/aratechserver)
-- =============================================================================
-- Objet : poser la fondation d'un pipeline de migrations reproductible
-- ("Déploiement -> Migration DB -> Application", voir §18 du brief), sans
-- toucher aux données existantes.
--
-- Aucune table métier n'est créée ici. Ce fichier ne fait que déclarer une
-- table de suivi des migrations, afin qu'un futur outil (ou un script shell
-- simple) puisse savoir quels fichiers de ce dossier ont déjà été appliqués
-- sur une base donnée, et ne jamais rejouer une migration deux fois par
-- erreur.
-- =============================================================================

CREATE TABLE IF NOT EXISTS schema_migrations (
    version     TEXT PRIMARY KEY,      -- ex: '002_hotspot_users'
    applied_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

INSERT INTO schema_migrations (version)
VALUES ('001_baseline')
ON CONFLICT (version) DO NOTHING;
