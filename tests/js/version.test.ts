import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { expect, it } from 'vitest';
import { PROTOCOL_VERSION } from '../../resources/js/token';

it('speaks the same protocol version as the PHP side', () => {
    const php = readFileSync(resolve(process.cwd(), 'src/Data/Token.php'), 'utf8')   // jsdom's import.meta.url is not a file: URL;
    const match = /public const VERSION = (\d+);/.exec(php);
    expect(match, 'Token::VERSION not found').not.toBeNull();
    expect(Number(match![1])).toBe(PROTOCOL_VERSION);
});
