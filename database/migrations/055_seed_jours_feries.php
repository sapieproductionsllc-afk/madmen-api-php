<?php
// Jours fériés République du Congo (Brazzaville) — 2025, 2026, 2027.
// Fixes : Nouvel An (01-01), Fête du Travail (05-01), Fête de l'Indépendance (08-15),
// Toussaint (11-01), Noël (12-25). Mobiles (calculés sur Pâques) : Lundi de Pâques,
// Ascension (+39 j), Lundi de Pentecôte (+50 j). INSERT IGNORE : date UNIQUE, rejouable.
return [
    'up' => <<<'SQL'
        INSERT IGNORE INTO jour_ferie (date, libelle) VALUES
        ('2025-01-01', 'Nouvel An'),
        ('2025-04-21', 'Lundi de Pâques'),
        ('2025-05-01', 'Fête du Travail'),
        ('2025-05-29', 'Ascension'),
        ('2025-06-09', 'Lundi de Pentecôte'),
        ('2025-08-15', 'Fête de l''Indépendance'),
        ('2025-11-01', 'Toussaint'),
        ('2025-12-25', 'Noël'),
        ('2026-01-01', 'Nouvel An'),
        ('2026-04-06', 'Lundi de Pâques'),
        ('2026-05-01', 'Fête du Travail'),
        ('2026-05-14', 'Ascension'),
        ('2026-05-25', 'Lundi de Pentecôte'),
        ('2026-08-15', 'Fête de l''Indépendance'),
        ('2026-11-01', 'Toussaint'),
        ('2026-12-25', 'Noël'),
        ('2027-01-01', 'Nouvel An'),
        ('2027-03-29', 'Lundi de Pâques'),
        ('2027-05-01', 'Fête du Travail'),
        ('2027-05-06', 'Ascension'),
        ('2027-05-17', 'Lundi de Pentecôte'),
        ('2027-08-15', 'Fête de l''Indépendance'),
        ('2027-11-01', 'Toussaint'),
        ('2027-12-25', 'Noël')
        SQL,
    'down' => "DELETE FROM jour_ferie WHERE date BETWEEN '2025-01-01' AND '2027-12-31'",
];
