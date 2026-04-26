#!/usr/bin/env bash

set -euo pipefail

# ---------------------------------------------------------------------------
# Detect OS and architecture
# ---------------------------------------------------------------------------
detect_os_arch() {
    local os arch

    case "$(uname -s)" in
        Linux)          os="Linux" ;;
        Darwin)         os="macOS" ;;
        MINGW*|MSYS*)   os="Windows" ;;
        *)
            echo "Unsupported OS: $(uname -s). Only Linux, macOS, and Windows are supported." >&2
            exit 1
            ;;
    esac

    case "$(uname -m)" in
        x86_64|amd64)       arch="X64" ;;
        aarch64|arm64)      arch="ARM64" ;;
        *)
            echo "Unsupported architecture: $(uname -m). Only x64 and ARM64 are supported." >&2
            exit 1
            ;;
    esac

    DETECTED_OS="${os}"
    DETECTED_ARCH="${arch}"
}

detect_os_arch

# ---------------------------------------------------------------------------
# Configuration (all overridable via environment)
# ---------------------------------------------------------------------------
LIBDAVE_VERSION="${LIBDAVE_VERSION:-v1.1.1/cpp}"
LIBDAVE_ASSET="${LIBDAVE_ASSET:-libdave-${DETECTED_OS}-${DETECTED_ARCH}-boringssl.zip}"
LIBDAVE_BASE_URL="${LIBDAVE_BASE_URL:-https://github.com/discord/libdave/releases/download}"
LIBDAVE_DEST_DIR="${LIBDAVE_DEST_DIR:-.cache/libdave}"
LIBDAVE_ZIP_PATH="${LIBDAVE_ZIP_PATH:-${LIBDAVE_DEST_DIR}/libdave.zip}"
LIBDAVE_VERSION_FILE="${LIBDAVE_DEST_DIR}/.version"
LIBDAVE_API_URL="${LIBDAVE_API_URL:-https://api.github.com/repos/discord/libdave/releases/tags}"

# Derive the library path from the detected OS
case "${DETECTED_OS}" in
    macOS)
        LIBDAVE_LIB_FILE="lib/libdave.dylib"
        ;;
    Windows)
        LIBDAVE_LIB_FILE="bin/libdave.dll"
        ;;
    *)
        LIBDAVE_LIB_FILE="lib/libdave.so"
        ;;
esac

# ---------------------------------------------------------------------------
# Validate destination directory
# ---------------------------------------------------------------------------
if [[ -z "${LIBDAVE_DEST_DIR}" ]]; then
    echo "Error: LIBDAVE_DEST_DIR cannot be empty." >&2
    exit 1
fi

if [[ "${LIBDAVE_DEST_DIR}" == "/" ]]; then
    echo "Error: LIBDAVE_DEST_DIR '${LIBDAVE_DEST_DIR}' is dangerous." >&2
    exit 1
fi

# ---------------------------------------------------------------------------
# SHA-256 verification helper
# ---------------------------------------------------------------------------
verify_sha256() {
    local file="$1" expected="$2" actual

    if command -v sha256sum > /dev/null 2>&1; then
        actual="$(sha256sum "$file" | awk '{print $1}')"
    elif command -v shasum > /dev/null 2>&1; then
        actual="$(shasum -a 256 "$file" | awk '{print $1}')"
    else
        echo "Error: neither sha256sum nor shasum found — cannot verify checksum" >&2
        return 1
    fi

    if [[ "${actual}" != "${expected}" ]]; then
        echo "SHA-256 mismatch for ${file}" >&2
        echo "  expected: ${expected}" >&2
        echo "  actual:   ${actual}" >&2
        return 1
    fi
}

# Fetch the expected SHA-256 digest from the GitHub Releases API
fetch_github_digest() {
    local version="$1" asset="$2" api_tag response digest

    # URL-encode the tag (replace / with %2F)
    api_tag="${version//\//%2F}"

    response="$(curl -L --fail --silent --show-error \
        -H "Accept: application/vnd.github+json" \
        "${LIBDAVE_API_URL}/${api_tag}" 2>/dev/null)" || {
        echo ""
        return
    }

    # Extract the digest for the matching asset.
    # The API returns "digest":"sha256:<hex>" per asset.
    if command -v jq > /dev/null 2>&1; then
        digest="$(echo "${response}" | jq -r \
            --arg name "${asset}" \
            '.assets[] | select(.name == $name) | .digest // empty')"
    else
        # Fallback: grep/sed for environments without jq.
        # Match the asset name, then find the next "digest" field.
        digest="$(echo "${response}" \
            | grep -o "\"name\":\"${asset}\"[^}]*\"digest\":\"[^\"]*\"" \
            | grep -o '"digest":"[^"]*"' \
            | sed 's/"digest":"//;s/"//')"
    fi

    # Strip the "sha256:" prefix
    if [[ "${digest}" == sha256:* ]]; then
        echo "${digest#sha256:}"
    else
        echo ""
    fi
}

# ---------------------------------------------------------------------------
# Skip if already installed at the correct version
# ---------------------------------------------------------------------------
mkdir -p "${LIBDAVE_DEST_DIR}"

if [[ -f "${LIBDAVE_VERSION_FILE}" ]] \
    && [[ "$(<"${LIBDAVE_VERSION_FILE}")" == "${LIBDAVE_VERSION}|${LIBDAVE_ASSET}" ]] \
    && [[ -f "${LIBDAVE_DEST_DIR}/${LIBDAVE_LIB_FILE}" ]] \
    && [[ -f "${LIBDAVE_DEST_DIR}/include/dave/dave.h" ]]; then
    echo "libdave already installed at ${LIBDAVE_DEST_DIR}"
    echo "DISCORDPHP_DAVE_LIBRARY=${LIBDAVE_DEST_DIR}/${LIBDAVE_LIB_FILE}"
    exit 0
fi

# ---------------------------------------------------------------------------
# Download
# ---------------------------------------------------------------------------
rm -rf "${LIBDAVE_DEST_DIR}/include" "${LIBDAVE_DEST_DIR}/lib" "${LIBDAVE_DEST_DIR}/bin" "${LIBDAVE_DEST_DIR}/licenses"

echo "Downloading ${LIBDAVE_ASSET} (${LIBDAVE_VERSION})..."
curl -L --fail --silent --show-error \
    -o "${LIBDAVE_ZIP_PATH}" \
    "${LIBDAVE_BASE_URL}/${LIBDAVE_VERSION//\//%2F}/${LIBDAVE_ASSET}"

# ---------------------------------------------------------------------------
# Verify SHA-256 against GitHub's release digest
# ---------------------------------------------------------------------------
echo "Fetching expected checksum from GitHub Releases API..."
EXPECTED_SHA="$(fetch_github_digest "${LIBDAVE_VERSION}" "${LIBDAVE_ASSET}")"

if [[ -n "${EXPECTED_SHA}" ]]; then
    echo "Verifying SHA-256 checksum..."
    if ! verify_sha256 "${LIBDAVE_ZIP_PATH}" "${EXPECTED_SHA}"; then
        rm -f "${LIBDAVE_ZIP_PATH}"
        echo "Checksum verification failed. Aborting." >&2
        exit 1
    fi
    echo "Checksum OK."
else
    echo "Warning: no checksum found for ${LIBDAVE_VERSION}/${LIBDAVE_ASSET} — skipping verification" >&2
fi

# ---------------------------------------------------------------------------
# Extract and validate
# ---------------------------------------------------------------------------
unzip -oq "${LIBDAVE_ZIP_PATH}" -d "${LIBDAVE_DEST_DIR}"

if [[ ! -f "${LIBDAVE_DEST_DIR}/${LIBDAVE_LIB_FILE}" ]]; then
    echo "Error: expected library not found at ${LIBDAVE_DEST_DIR}/${LIBDAVE_LIB_FILE}" >&2
    exit 1
fi

if [[ ! -f "${LIBDAVE_DEST_DIR}/include/dave/dave.h" ]]; then
    echo "Error: expected header not found at ${LIBDAVE_DEST_DIR}/include/dave/dave.h" >&2
    exit 1
fi

printf '%s' "${LIBDAVE_VERSION}|${LIBDAVE_ASSET}" > "${LIBDAVE_VERSION_FILE}"

echo "Installed libdave to ${LIBDAVE_DEST_DIR}"
echo "DISCORDPHP_DAVE_LIBRARY=${LIBDAVE_DEST_DIR}/${LIBDAVE_LIB_FILE}"
