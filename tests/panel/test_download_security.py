import importlib.util
import io
from pathlib import Path
import tempfile
import unittest
from unittest.mock import MagicMock, patch
import zipfile

source = Path(__file__).resolve().parents[2] / 'panel/daemon/pack_resolver.py'
spec = importlib.util.spec_from_file_location('pack_resolver', source)
resolver = importlib.util.module_from_spec(spec)
spec.loader.exec_module(resolver)

class DownloadSecurity(unittest.TestCase):
    def test_paths_stay_inside_server(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp) / 'server'
            root.mkdir()
            outside = Path(tmp) / 'outside'
            outside.mkdir()
            (root / 'link').symlink_to(outside, target_is_directory=True)
            for path in ('../secret', '/tmp/secret', 'link/secret', 'mods/../../secret', 'mods\\secret'):
                with self.subTest(path=path), self.assertRaises(resolver.ResolveError):
                    resolver._safe_work_path(root, path)
            self.assertEqual(resolver._safe_work_path(root, 'mods/a.jar'), root / 'mods/a.jar')

    def test_urls_rejected_before_network_access(self):
        with patch.object(resolver.requests, 'get') as get:
            for url in ('http://127.0.0.1:8001', 'https://127.0.0.1', 'https://cdn.modrinth.com.evil.test/a', 'https://cdn.modrinth.com:8001/a', 'file:///etc/passwd', 'https://user:pass@cdn.modrinth.com/a'):
                with self.subTest(url=url), self.assertRaises(resolver.ResolveError):
                    resolver._download_stream(url, Path('unused'), lambda *_:None, 'test')
            get.assert_not_called()

    def test_redirect_to_private_service_is_blocked(self):
        response = MagicMock()
        response.status_code = 302
        response.headers = {'Location':'http://127.0.0.1:8001/api/daemon/stop/1'}
        response.__enter__.return_value = response
        with patch.object(resolver.requests, 'get', return_value=response) as get:
            with self.assertRaises(resolver.ResolveError):
                resolver._download_stream('https://cdn.modrinth.com/test', Path('unused'), lambda *_:None, 'test')
            self.assertEqual(get.call_count, 1)
            self.assertFalse(get.call_args.kwargs['allow_redirects'])

    def test_download_and_archive_sandbox(self):
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp) / 'server'
            root.mkdir()
            response = MagicMock()
            response.status_code = 200
            response.__enter__.return_value = response
            response.iter_content.return_value = [b'jar']
            with patch.object(resolver.requests, 'get', return_value=response):
                resolver._download_stream('https://cdn.modrinth.com/a.jar', root/'a.jar', lambda *_:None, 'test')
            self.assertEqual((root/'a.jar').read_bytes(), b'jar')
            buffer = io.BytesIO()
            with zipfile.ZipFile(buffer, 'w') as archive:
                archive.writestr('overrides/../../outside', 'unsafe')
                archive.writestr('overrides/config/safe.txt', 'safe')
            with zipfile.ZipFile(buffer) as archive:
                self.assertEqual(resolver._extract_overrides(archive, root, 'overrides', lambda *_:None), 1)
            self.assertFalse((Path(tmp)/'outside').exists())
            self.assertEqual((root/'config/safe.txt').read_text(), 'safe')

if __name__ == '__main__':
    unittest.main()
