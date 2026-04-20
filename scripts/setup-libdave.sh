#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

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
LIBDAVE_CHECKSUMS_FILE="${LIBDAVE_CHECKSUMS_FILE:-${SCRIPT_DIR}/libdave-checksums.sha256}"

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
# SHA-256 verification helper
# ---------------------------------------------------------------------------
verify_sha256() {
    local file="$1" expected="$2" actual

    if command -v sha256sum > /dev/null 2>&1; then
        actual="$(sha256sum "$file" | awk '{print $1}')"
    elif command -v shasum > /dev/null 2>&1; then
        actual="$(shasum -a 256 "$file" | awk '{print $1}')"
    else
        echo "Warning: neither sha256sum nor shasum found — skipping checksum verification" >&2
        return 0
    fi

    if [[ "${actual}" != "${expected}" ]]; then
        echo "SHA-256 mismatch for ${file}" >&2
        echo "  expected: ${expected}" >&2
        echo "  actual:   ${actual}" >&2
        return 1
    fi
}

# Look up the expected checksum from the checksums file
lookup_checksum() {
    local version="$1" asset="$2" key

    key="${version}/${asset}"

    if [[ ! -f "${LIBDAVE_CHECKSUMS_FILE}" ]]; then
        echo ""
        return
    fi

    grep -F "${key}" "${LIBDAVE_CHECKSUMS_FILE}" | awk '{print $1}' | head -1
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
# Verify SHA-256
# ---------------------------------------------------------------------------
EXPECTED_SHA="$(lookup_checksum "${LIBDAVE_VERSION}" "${LIBDAVE_ASSET}")"

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
