# legacy/ (quarantine, reference only)

This folder holds the parts of the inherited chsPetPantry / CCDA Food Pantry code that PFPMS rebuilds from. See `docs/PFPMS_Implementation_Plan.md` (Phase 0, §7).

- **Not runnable.** The DB connection (`database/dbinfo.php`) and many of the files these pages include were deleted in Phase 0 because they held secrets or dead features.
- **Never deployed or included.** New code under `src/` and `public/` must not `require` anything from here. CI enforces this from Phase 1.
- **Temporary.** Each file is deleted in the phase that replaces it (P1, P2, P3, P4, P6, P7). The folder is gone by the end of P7.
- **Nothing is lost.** The complete original tree is at the git tag `legacy-baseline`.
