#!/usr/bin/env python3
"""
Builds docs/planning/erd.mdj — a StarUML ER data model — from database/schema.sql.

Generated, not hand-drawn, for the same reason the seed and the icon sprite are:
a diagram maintained by hand drifts from the schema, and §8.2 lists a diagram
that does not match the code as a critical failure. Re-run this after any change
to schema.sql and the model follows automatically.

    python3 scripts/export-staruml.py

Open the result with StarUML (File ▸ Open). The entities appear in the Model
Explorer; drag them onto an ERD diagram for the picture, or use the ERD
extension's DDL generator.

WHAT THE .mdj DOES NOT CARRY, and why database/schema.sql stays authoritative:
StarUML's ER model has no vocabulary for CHECK constraints, secondary indexes,
ON DELETE/ON UPDATE actions, storage engine or charset. This project depends on
several of those for correctness — CHECK (quantity >= 0) is the last line of
defence against negative stock (ARCH-02), and UNIQUE (product_id, warehouse_id)
is what makes SELECT ... FOR UPDATE lock exactly one row rather than a range.
DDL generated from this model would silently omit them. Use it to read and
present the structure; keep creating the database from schema.sql.
"""
import json
import re
import sys
from pathlib import Path

SCHEMA = Path("database/schema.sql")
OUTPUT = Path("docs/planning/erd.mdj")

# MySQL types mapped onto the fixed vocabulary StarUML's ER profile accepts.
# Anything unrecognised falls back to VARCHAR, which is visible in the diagram
# rather than silently wrong.
TYPE_MAP = {
    "INT": "INTEGER",
    "BIGINT": "BIGINT",
    "SMALLINT": "SMALLINT",
    "TINYINT": "TINYINT",
    "DECIMAL": "DECIMAL",
    "VARCHAR": "VARCHAR",
    "CHAR": "CHAR",
    "TEXT": "TEXT",
    "DATE": "DATE",
    "DATETIME": "DATETIME",
    "TIMESTAMP": "TIMESTAMP",
    "ENUM": "VARCHAR",
}


def strip_comments(sql: str) -> str:
    return re.sub(r"--[^\n]*", "", sql)


def parse(sql: str) -> list[dict]:
    """Returns one dict per CREATE TABLE, in the order the schema declares them."""
    tables = []
    for match in re.finditer(
        r"CREATE TABLE\s+(\w+)\s*\((.*?)\)\s*ENGINE=", sql, re.S | re.I
    ):
        name, body = match.group(1), match.group(2)
        # Split on top-level commas only: DECIMAL(14,2) and ENUM('a','b')
        # contain commas that do not separate definitions.
        parts, depth, current = [], 0, ""
        for char in body:
            if char == "(":
                depth += 1
            elif char == ")":
                depth -= 1
            if char == "," and depth == 0:
                parts.append(current.strip())
                current = ""
            else:
                current += char
        parts.append(current.strip())

        columns, primary, uniques, foreign = [], [], [], []
        for part in parts:
            if not part:
                continue
            upper = part.upper()
            if upper.startswith("PRIMARY KEY"):
                primary = [c.strip() for c in re.search(r"\((.*?)\)", part).group(1).split(",")]
            elif upper.startswith("UNIQUE KEY") or upper.startswith("UNIQUE ("):
                uniques.append([c.strip() for c in re.findall(r"\(([^)]*)\)", part)[-1].split(",")])
            elif "FOREIGN KEY" in upper:
                fk = re.search(
                    r"FOREIGN KEY\s*\(([^)]*)\)\s*REFERENCES\s+(\w+)\s*\(([^)]*)\)", part, re.I
                )
                if fk:
                    foreign.append({
                        "column": fk.group(1).strip(),
                        "table": fk.group(2).strip(),
                        "references": fk.group(3).strip(),
                    })
            elif upper.startswith(("KEY ", "INDEX ", "CONSTRAINT")) and "CHECK" in upper:
                continue  # CHECK constraints have no ER equivalent; see the module docstring.
            elif upper.startswith(("KEY ", "INDEX ")):
                continue  # Secondary indexes likewise.
            else:
                column = re.match(r"(\w+)\s+([A-Za-z]+)(?:\(([^)]*)\))?(.*)", part, re.S)
                if column is None:
                    continue
                raw_type = column.group(2).upper()
                columns.append({
                    "name": column.group(1),
                    "type": TYPE_MAP.get(raw_type, "VARCHAR"),
                    "length": (column.group(3) or "").split(",")[0].strip(),
                    "nullable": "NOT NULL" not in column.group(4).upper(),
                })

        tables.append({
            "name": name,
            "columns": columns,
            "primary": primary,
            "uniques": uniques,
            "foreign": foreign,
        })
    return tables


def build(tables: list[dict]) -> dict:
    project_id = "AAAAAAGProject"
    model_id = "AAAAAAGDataModel"
    elements = []
    entity_ids = {}

    for index, table in enumerate(tables):
        entity_id = f"ENT{index:03d}"
        entity_ids[table["name"]] = entity_id
        unique_columns = {c for group in table["uniques"] for c in group}
        foreign_columns = {fk["column"] for fk in table["foreign"]}

        columns = []
        for position, column in enumerate(table["columns"]):
            type_name = column["type"]
            if column["length"] and type_name in ("VARCHAR", "CHAR", "DECIMAL"):
                pass  # length is carried in its own field, below
            columns.append({
                "_type": "ERDColumn",
                "_id": f"{entity_id}C{position:02d}",
                "_parent": {"$ref": entity_id},
                "name": column["name"],
                "type": type_name,
                "length": int(column["length"]) if column["length"].isdigit() else 0,
                "primaryKey": column["name"] in table["primary"],
                "foreignKey": column["name"] in foreign_columns,
                "unique": column["name"] in unique_columns,
                "nullable": column["nullable"] and column["name"] not in table["primary"],
            })

        elements.append({
            "_type": "ERDEntity",
            "_id": entity_id,
            "_parent": {"$ref": model_id},
            "name": table["name"],
            "columns": columns,
        })

    # Relationships are emitted after every entity exists, so a foreign key can
    # point at a table declared later in the file.
    relationship_index = 0
    for table in tables:
        for fk in table["foreign"]:
            target = entity_ids.get(fk["table"])
            if target is None:
                print(f"  warning: {table['name']}.{fk['column']} references unknown "
                      f"table {fk['table']}", file=sys.stderr)
                continue
            source = entity_ids[table["name"]]
            relationship_id = f"REL{relationship_index:03d}"
            relationship_index += 1
            elements.append({
                "_type": "ERDRelationship",
                "_id": relationship_id,
                "_parent": {"$ref": model_id},
                "name": f"{table['name']}_{fk['column']}",
                "end1": {
                    "_type": "ERDRelationshipEnd",
                    "_id": f"{relationship_id}E1",
                    "_parent": {"$ref": relationship_id},
                    "reference": {"$ref": target},
                    "cardinality": "1",
                },
                "end2": {
                    "_type": "ERDRelationshipEnd",
                    "_id": f"{relationship_id}E2",
                    "_parent": {"$ref": relationship_id},
                    "reference": {"$ref": source},
                    # A row on the foreign-key side may appear many times, or not
                    # at all: a category with no products is legal.
                    "cardinality": "0..*",
                },
            })

    return {
        "_type": "Project",
        "_id": project_id,
        "name": "Stockpile",
        "ownedElements": [{
            "_type": "ERDDataModel",
            "_id": model_id,
            "_parent": {"$ref": project_id},
            "name": "Stockpile schema",
            "documentation": (
                "Generated from database/schema.sql by scripts/export-staruml.py. "
                "Do not edit by hand -- the next regeneration overwrites it. "
                "CHECK constraints, secondary indexes and ON DELETE/ON UPDATE actions "
                "have no ER equivalent and are NOT represented here; schema.sql remains "
                "the authoritative definition."
            ),
            "ownedElements": elements,
        }],
    }


def main() -> int:
    if not SCHEMA.is_file():
        raise SystemExit(f"{SCHEMA} not found. Run this from the repository root.")

    tables = parse(strip_comments(SCHEMA.read_text(encoding="utf-8")))
    if not tables:
        raise SystemExit("No CREATE TABLE statements were parsed; refusing to write an empty model.")

    OUTPUT.write_text(json.dumps(build(tables), indent=2) + "\n", encoding="utf-8")

    relationships = sum(len(t["foreign"]) for t in tables)
    columns = sum(len(t["columns"]) for t in tables)
    print(f"wrote {OUTPUT}")
    print(f"  {len(tables)} entities, {columns} columns, {relationships} relationships")
    for table in tables:
        print(f"    {table['name']:<22} {len(table['columns']):>2} cols, "
              f"{len(table['foreign'])} fk, pk={','.join(table['primary']) or '-'}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
